<?php

namespace WMC\AppLoader;

use Symfony\Component\ClassLoader\ApcClassLoader;

use Symfony\Component\Debug\Debug;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Symfony\Component\HttpFoundation\Request;

/**
 * Instantiates a Symfony Kernel and run the Request handler
 * Loads a default configuration containing the environment and other machine-specific parameters
 */
class AppLoader
{
    protected static $optionsFile = 'config/app_loader.ini';

    /**
     * Root Symonfy dir (app directory)
     * @var string
     */
    protected $kernelDir;

    /**
     * ClassLoader, must have a findFile method
     * @var object
     */
    protected $classLoader;

    /**
     * @var array
     */
    protected $options = null;

    /**
     * Symfony Kernel
     * @var Kernel
     */
    protected $kernel = null;

    /**
     * Symfony Console Application
     * @var Application
     */
    protected $application = null;

    /**
     * Console input to initialize additional options
     * @var InputInterface
     */
    protected $input;

    public function __construct($kernelDir, $classLoader, $optionFile = null)
    {
        if (!is_string($kernelDir) || !is_file($kernelDir.'/AppKernel.php')) {
            throw new \InvalidArgumentException('Symfony AppLoader must be given a Symfony app dir path');
        }

        $this->kernelDir   = $kernelDir;
        $this->classLoader = $classLoader;
        $this->loadOptions($optionFile);
    }

    public function getDefaultOptionsFile()
    {
        return $this->kernelDir . '/' . static::$optionsFile;
    }

    protected function loadOptions($file = null)
    {
        if (null === $file) {
            // Try to load default file, but do not return an exception if it does not exist
            $file = $this->getDefaultOptionsFile();
            if (!is_file($file)) {
                $this->options = array();
                return;
            }
        } elseif (!is_file($file)) {
            throw new \InvalidArgumentException($file.' does not exist');
        }

        $options = parse_ini_file($file);

        if (!$options) {
            throw new \RuntimeException($file.' is not a valid ini file.');
        }

        $this->options = $options;
    }

    protected function processOptions()
    {
        if (!isset($this->options['environment'])) {
            $this->options['environment'] = getenv('SYMFONY_ENV') ?: 'prod';
        }

        if (null !== $this->input) {
            $this->options['environment'] = $this->input->getParameterOption(['--env', '-e'], $this->options['environment']);
        }

        if (!isset($this->options['localhost_only']) || $this->options['environment'] == 'prod') {
            $this->options['localhost_only'] = false;
        }

        if (getenv('SYMFONY_DEBUG') === '0'
            || (null !== $this->input
                && $this->input->hasParameterOption(['--no-debug', '']))
        ) {
            $this->options['debug'] = false;
        }

        if (!isset($this->options['debug'])) {
            $this->options['debug'] = $this->options['environment'] == 'dev';
        }

        $this->options['umask_fix'] = isset($this->options['umask_fix'])
                                      ? (bool) $this->options['umask_fix']
                                      : false;

        if (empty($this->options['apc_cache_id']) || $this->options['environment'] != 'prod') {
            $this->options['apc_cache_id'] = false;
        }

        if (!isset($this->options['http_cache']) || $this->options['environment'] != 'prod') {
            $this->options['http_cache'] = false;
        }
    }

    /**
     * Get option
     *
     * @param  string $key
     * @return mixed
     */
    public function __get($key)
    {
        return isset($this->options[$key]) ? $this->options[$key] : null;
    }

    /**
     * Set option
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value)
    {
        return $this->options[$key] = $value;
    }

    public function handleRequest()
    {
        $this->loadBootstrap();

        $kernel = $this->getKernel();

        Request::enableHttpMethodParameterOverride();
        $request = Request::createFromGlobals();
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    }
 
    public function handleConsole(InputInterface $input = null, OutputInterface $output = null)
    {
        $this->input = $input;
        $this->options['http_cache'] = false;
        set_time_limit(0);

        return $this->getApplication()->run($input, $output);
    }

    public function getApplication()
    {
        if (null === $this->application) {
            $this->buildApplication();
        }

        return $this->application;
    }

    public function getKernel()
    {
        if (null === $this->kernel) {
            $this->buildKernel();
        }

        return $this->kernel;
    }

    protected function loadBootstrap()
    {
        include_once $this->getBootstrapPath();
    }

    protected function getBootstrapPath()
    {
        if (is_file($path = $this->kernelDir.'/../var/bootstrap.php.cache')) {
            return $path;
        } elseif (is_file($path = $this->kernelDir.'/bootstrap.php.cache')) {
            return $path;
        } else {
            throw new \RuntimeException('Cannot find a bootstrap file');
        }
    }

    protected function buildApplication()
    {
        $this->application = new Application($this->getKernel());
    }

    protected function buildKernel()
    {
        $this->processOptions();

        $this->beforeKernel();
        $this->loadKernel();
        $this->afterKernel();
    }

    protected function beforeKernel()
    {
        if ('cli' !== PHP_SAPI) {
            $this->enforceIpProtection();
        }

        $this->enableDebug();
        $this->enableUmaskFix();
        $this->enableApcClassLoader();
    }

    protected function afterKernel()
    {
        $this->enableHttpCache();
    }

    /**
     * Prevent access to debug front controllers that are deployed by accident
     * to production servers.
     */
    protected function enforceIpProtection()
    {
        if ($this->options['localhost_only']) {
            if (isset($_SERVER['HTTP_CLIENT_IP'])
                || isset($_SERVER['HTTP_X_FORWARDED_FOR'])
                || !in_array(@$_SERVER['REMOTE_ADDR'], array('127.0.0.1', 'fe80::1', '::1'))
            ) {
                header('HTTP/1.0 403 Forbidden');
                exit('You are not allowed to access this file.');
            }
        }
    }

    protected function enableDebug()
    {
        if ($this->options['debug'] && class_exists('Symfony\Component\Debug\Debug', true)) {
            Debug::enable();
        }
    }

    /**
     * If you don't want to setup permissions the proper way, this will ensure all created files are writable
     * @link http://symfony.com/doc/current/book/installation.html#configuration-and-setup for more information
     */
    protected function enableUmaskFix()
    {
        if ($this->options['umask_fix']) {
            umask(0000);
        }
    }

    /**
     * if $apc_cache_id is specified, use APC for autoloading to improve performance.
     * $apc_cache_id needs to be a unique prefix in order to prevent cache key conflicts with other applications also using APC.
     */
    protected function enableApcClassLoader()
    {
        if ($this->options['apc_cache_id']) {
            $this->classLoader = new ApcClassLoader($this->options['apc_cache_id'], $this->classLoader);
            $this->classLoader->register(true);
        }
    }

    protected function loadKernel()
    {
        if (!class_exists('AppKernel', false)) {
            require_once $this->kernelDir.'/AppKernel.php';
        }

        $this->kernel = new \AppKernel($this->options['environment'], $this->options['debug']);
        $this->kernel->loadClassCache();
    }

    /**
     * Activates Symfony's reverse proxy
     */
    protected function enableHttpCache()
    {
        if ($this->options['http_cache']) {
            require_once $this->kernelDir.'/AppCache.php';

            $this->kernel = new \AppCache($this->kernel);
        }
    }
}
