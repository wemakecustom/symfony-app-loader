<?php

namespace WMC\AppLoader;

use Composer\IO\IOInterface;
use Composer\Script\Event;

class ScriptHandler
{
    public static function buildParameters(Event $event)
    {
        $extras = $event->getComposer()->getPackage()->getExtra();

        if (empty($extras['wmc-app-loader']['file'])) {
            if (empty($extras['symfony-app-dir'])) {
                throw new \InvalidArgumentException('Either extra.symfony-app-dir or extra.wmc-app-loader.file setting are required to use this script handler.');
            }
            $app_dir = $extras['symfony-app-dir'];
            $app_loader = new AppLoader($app_dir, null);
            $realFile = $app_loader->getDefaultOptionsFile();
        } else {
            $realFile = $extras['wmc-app-loader']['file'];
        }

        if (empty($extras['wmc-app-loader']['dist-file'])) {
            $distFile = $realFile.'.dist';
        } else {
            $distFile = $extras['wmc-app-loader']['dist-file'];
        }

        $keepOutdatedParams = false;
        if (isset($extras['wmc-app-loader']['keep-outdated'])) {
            $keepOutdatedParams = (boolean)$extras['wmc-app-loader']['keep-outdated'];
        }

        if (!is_file($distFile)) {
            throw new \InvalidArgumentException(sprintf('The dist file "%s" does not exist. Check your dist-file config or create it.', $distFile));
        }

        $exists = is_file($realFile);

        $io = $event->getIO();

        $action = $exists ? 'Updating' : 'Creating';
        $io->write(sprintf('<info>%s the "%s" file.</info>', $action, $realFile));

        // Find the expected params
        $expectedValues = parse_ini_file($distFile);
        if (!isset($expectedValues['environment'])) {
            throw new \InvalidArgumentException('The dist file seems invalid.');
        }

        // find the actual params
        $actualValues = array();
        if ($exists) {
            $existingValues = parse_ini_file($realFile);
            if (!is_array($existingValues)) {
                throw new \InvalidArgumentException(sprintf('The existing "%s" file does not contain an array', $realFile));
            }
            $actualValues = array_merge($actualValues, $existingValues);
        }

        if (!$keepOutdatedParams) {
            // Remove the outdated params
            foreach ($actualValues as $key => $value) {
                if (!array_key_exists($key, $expectedValues)) {
                    unset($actualValues[$key]);
                }
            }
        }

        $envMap = empty($extras['wmc-app-loader']['env-map']) ? array() : (array) $extras['wmc-app-loader']['env-map'];

        // Add the params coming from the environment values
        $actualValues = array_replace($actualValues, self::getEnvValues($envMap));

        $actualValues = self::getParams($io, $expectedValues, $actualValues);

        file_put_contents($realFile, "; This file is auto-generated during the composer install\n" . self::dump($actualValues));
    }

    private static function getEnvValues(array $envMap)
    {
        $params = array();
        foreach ($envMap as $param => $env) {
            $value = getenv($env);
            if ($value) {
                $params[$param] = $value;
            }
        }

        return $params;
    }

    private static function getParams(IOInterface $io, array $expectedParams, array $actualParams)
    {
        // Simply use the expectedParams value as default for the missing params.
        if (!$io->isInteractive()) {
            return array_replace($expectedParams, $actualParams);
        }

        $isStarted = false;

        foreach ($expectedParams as $key => $message) {
            if (array_key_exists($key, $actualParams)) {
                continue;
            }

            if (!$isStarted) {
                $isStarted = true;
                $io->write('<comment>Some app loader parameters are missing. Please provide them.</comment>');
            }

            $default = self::dumpSingle($message);
            $value = $io->ask(sprintf('<question>%s</question> (<comment>%s</comment>):', $key, $default), $default);

            $actualParams[$key] = self::parseSingle($value);
        }

        return $actualParams;
    }

    private static function dump(array $params)
    {
        $ini = "";

        foreach ($params as $key => $value) {
            $ini .= "$key=" . self::dumpSingle($value) . "\n";
        }

        return $ini;
    }

    private static function dumpSingle($value)
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        } elseif (empty($value)) {
            return 'false';
        } elseif ($value == '1') {
            return 'true';
        } else {
            return "\"$value\"";
        }
    }

    private static function parseSingle($value)
    {
        $ini = parse_ini_string("value=$value");

        return $ini['value'];
    }
}
