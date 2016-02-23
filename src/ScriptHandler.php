<?php

namespace WMC\AppLoader;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use WMC\Composer\Utils\ConfigFile\IniConfigFile;
use WMC\Composer\Utils\Composer\PackageLocator;

class ScriptHandler
{
    public static function buildParameters(Event $event)
    {
        $extras = $event->getComposer()->getPackage()->getExtra();

        if (empty($extras['wmc-app-loader']['file'])) {
            if (empty($extras['symfony-app-dir'])) {
                throw new \InvalidArgumentException('Either extra.symfony-app-dir or extra.wmc-app-loader.file setting are required to use this script handler.');
            }
            $appDir = $extras['symfony-app-dir'];
            $appLoader = new AppLoader($appDir, null);
            $realFile = $appLoader->getDefaultOptionsFile();
        } else {
            $realFile = $extras['wmc-app-loader']['file'];
        }

        if (empty($extras['wmc-app-loader']['dist-file'])) {
            $distFile = $realFile.'.dist';

            if (!is_file($distFile)) {
                // using packaged dist file
                $distFile = PackageLocator::getPackagePath($event->getComposer(), 'wemakecustom/symfony-app-loader') . '/app/config/app_loader.ini.dist';
            }
        } else {
            $distFile = $extras['wmc-app-loader']['dist-file'];
        }

        $keepOutdatedParams = false;
        if (isset($extras['wmc-app-loader']['keep-outdated'])) {
            $keepOutdatedParams = (bool) $extras['wmc-app-loader']['keep-outdated'];
        }

        $yml = new IniConfigFile($event->getIO());
        $yml->setKeepOutdatedParams($keepOutdatedParams);
        $yml->updateFile($realFile, $distFile);
    }
}
