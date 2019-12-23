#!/usr/bin/env php
<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', 'php.errors');

$autoloader = __DIR__.'/vendor/autoload.php';
if (!\is_file($autoloader)) {
    echo 'Composer autoloader not found, run `composer install` first!', PHP_EOL;
    exit;
}

require_once $autoloader;

\DOF\Testing\Util::gwt(['./gwt'], [], true);
