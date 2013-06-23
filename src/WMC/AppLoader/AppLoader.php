<?php

namespace WMC\AppLoader;

use Symfony\Component\ClassLoader\ApcClassLoader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Debug\Debug;

/**
 * Instantiates a Symfony Kernel and run the Request handler
 * Loads a default configuration containing the environment and other machine-specific parameters
 */
class AppLoader
{
    protected static $options_file = 'config/app_loader.ini';

    /**
     * Root Symonfy dir (app directory)
     * @var string
     */
    protected $kernel_dir;

    /**
     * ClassLoader, must implement findFile
     * @var object
     */
    protected $class_loader;

    /**
     * @var array
     */
    protected $options = null;

    /**
     * Symfony Kernel
     * @var Symfony\Component\HttpKernel\Kernel
     */
    protected $kernel = null;

    function __construct($kernel_dir, $class_loader)
    {
        if (!is_string($kernel_dir) || !is_file("$kernel_dir/AppKernel.php")) {
            throw new \InvalidArgumentException('Symfony AppLoader must be passed Symfony\'s app dir');
        }

        $this->kernel_dir   = $kernel_dir;
        $this->class_loader = $class_loader;
    }

    public function getDefaultOptionsFile()
    {
        return $this->kernel_dir . '/' . static::$options_file;
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
            throw new \InvalidArgumentException("$file does not exist");
        }

        $options = parse_ini_file($file);

        if (!$options) {
            throw new \RuntimeException("{$file} is not a valid ini file.");
        }

        $this->options = $options;
    }

    protected function processOptions()
    {
        if (!isset($this->options['environment'])) {
            $this->options['environment'] = 'prod';
        }

        if (!isset($this->options['localhost_only']) || $this->options['environment'] != 'dev') {
            $this->options['localhost_only'] = false;
        }

        if (!isset($this->options['debug'])) {
            $this->options['debug'] = $this->options['environment'] == 'dev';
        }

        if (isset($this->options['umask_fix'])) {
            $this->options['umask_fix'] = (bool) $this->options['umask_fix'];
        } else {
            $this->options['umask_fix'] = false;
        }

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
        return array_key_exists($key, $this->options) ? $this->options[$key] : null;
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

    public function run()
    {
        if (null === $this->options) {
            $this->loadOptions();
        }
        $this->processOptions();

        $this->beforeKernel();
        $this->loadKernel();
        $this->afterKernel();
        $this->handleRequest();
    }

    protected function beforeKernel()
    {
        $this->enforceIpProtection();
        $this->enableDebug();
        $this->enableUmaskFix();
        $this->enableApcClassLoader();
    }

    protected function afterKernel()
    {
        $this->enableHttpCache();
    }

    /**
     * This check prevents access to debug front controllers that are deployed by accident to production servers.
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
            $this->class_loader = new ApcClassLoader($this->options['apc_cache_id'], $this->class_loader);
            $this->class_loader->register(true);
        }
    }

    protected function loadKernel()
    {
        require_once $this->kernel_dir . '/AppKernel.php';

        $this->kernel = new \AppKernel($this->options['environment'], $this->options['debug']);
        $this->kernel->loadClassCache();
    }

    /**
     * Activates Symfony's reverse proxy
     */
    protected function enableHttpCache()
    {
        if ($this->options['http_cache']) {
            require_once $this->kernel_dir . '/AppCache.php';

            $this->kernel = new \AppCache($this->kernel);
        }
    }

    protected function handleRequest()
    {
        Request::enableHttpMethodParameterOverride();
        $request = Request::createFromGlobals();
        $response = $this->kernel->handle($request);
        $response->send();
        $this->kernel->terminate($request, $response);
    }
}