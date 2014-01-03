# Unified front controller for Symfony.

This package provides a unified front controller for Symfony, merging all the
options into a single AppLoader. The options are all overridable using a custom
front controller or extending the AppLoader.

This tool uses a script that will ask for your parameters when running composer
install or update, very similar to incenteev/composer-parameter-handler.

## Installation

Add the following in your root composer.json file:

```json
{
    "require": {
        "wemakecustom/symfony-app-loader": "~1.0@dev"
    },
    "scripts": {
        "post-install-cmd": [
            "WMC\\AppLoader\\ScriptHandler::buildParameters",
        ],
        "post-update-cmd": [
            "WMC\\AppLoader\\ScriptHandler::buildParameters",
        ]
    }
}
```

Replace ``web/app.php`` with the one in this package.
See the section **Overridding options using front controllers** for more details.

Replace ``app/console`` with the one in this package.

Ignore your ini file in .gitignore:
```
/app/config/app_loader.ini
```

## Composer script configuration

### Different file and dist file

The ``app/config/app_loader.ini`` will then be created or updated by the
composer script, to match the structure of the dist file ``app/config/app_loader.ini.dist``
by asking you the missing parameters. If no ``app/config/app_loader.ini.dist``
is available in your Symfony installation, it will use a default one.

By default, the dist file is assumed to be in the same place than the parameters
file, suffixed by ``.dist``. This can be changed in the configuration:

```json
{
    "extra": {
        "wmc-app-loader": {
            "file": "app/config/app_loader.ini",
            "dist-file": "some/other/folder/to/other/parameters/file/app_loader.ini.dist"
        }
    }
}
```

### Keep outdated parameters

The script handler will ask you interactively for parameters which are missing
in the parameters file, using the value of the dist file as default value.
All prompted values are parsed as inline INI, to allow you to define ``true``,
``false`` or numbers easily.
If composer is run in a non-interactive mode, the values of the dist file
will be used for missing parameters.

Warning: This script removes outdated params from ``app_loader.ini`` which are 
not in ``app_loader.ini.dist``. If you need to keep outdated params you can use
`keep-outdated` param in the configuration:
```json
{
    "extra": {
        "wmc-app-loader": {
            "keep-outdated": true,
        }
    }
}
```

### Using environment variables to set the parameters

For your prod environment, using an interactive prompt may not be possible
when deploying. In this case, you can rely on environment variables to provide
the parameters. This is achieved by providing a map between environment variables
and the parameters they should fill:

```json
{
    "extra": {
        "wmc-app-loader": {
            "env-map": {
                "my_first_param": "MY_FIRST_PARAM",
                "my_second_param": "MY_SECOND_PARAM"
            }
        }
    }
}
```

If an environment variable is set, its value will always replace the value
set in the existing parameters file.

As environment variables can only be strings, they are also parsed as inline
INI values to allows specifying ``false``, ``true`` or numbers easily.

Warning: This parameters handler will overwrite any comments or spaces into
your app_loader.ini file so handle with care. So if you want to give format
and comments to your parameter's file you should do it on your dist version.

## Customizing behavior

### Overridding options using front controllers

You can find the standard controllers in `web/app*.php`. It is a good idea to
copy them all for a development configuration. While not strictly required,
it may be good to leave only web/app.php in a production environment.

As you can see in the examples, you can override any option after instantiating
the AppLoader, but before `$app_loader->run();`

Available options are:

 * `environment`: (dev|test|prod)
 * `debug`: (true|false) guessed automatically on environment == dev
 * `http_cache`: (true|false) whether or not to use Symfony HTTP reverse proxy
 * `localhost_only`: (true|false) whether or not to limit request to localhost when in dev mode
 * `umask_fix`: (true|false) whether or not to change the umask to 0000
 * `apc_cache_id`: (false|string) if a valid string, use this as an APC id for the caches

### Overridding behavior by extending AppLoader

The provided AppLoader is fully overridable. You can extend it and modify your
fron controllers to use yours instead. See sample/AppLoader for an example.
