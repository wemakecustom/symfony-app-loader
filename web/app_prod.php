<?php

use WMC\AppLoader\AppLoader;

$loader = require __DIR__.'/../app/autoload.php';

$app_loader = new AppLoader(__DIR__ . '/../app', $loader);
$app_loader->environment = 'prod';
$app_loader->handleRequest();
