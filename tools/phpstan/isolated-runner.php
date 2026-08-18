<?php

declare(strict_types=1);

// PHPStan normally discovers Prism's Composer autoloader because its PHAR
// lives under Prism/vendor. That also loads Laravel's global helpers, whose
// config() function collides with CodeIgniter's. Run a copied PHAR and load
// only the scanned project plus the official CI extension.

$projectAutoload = getenv('PRISM_PROJECT_AUTOLOAD');

if (is_string($projectAutoload) && is_file($projectAutoload)) {
    require_once $projectAutoload;
}

$extensionSource = getenv('PRISM_CI_PHPSTAN_SOURCE');

if (is_string($extensionSource) && is_dir($extensionSource)) {
    spl_autoload_register(static function (string $class) use ($extensionSource): void {
        $prefix = 'CodeIgniter\\PHPStan\\';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';
        $file = rtrim($extensionSource, '/\\').DIRECTORY_SEPARATOR.$relative;

        if (is_file($file)) {
            require_once $file;
        }
    });
}

$phar = getenv('PRISM_PHPSTAN_PHAR');

if (! is_string($phar) || ! is_file($phar)) {
    fwrite(STDERR, 'The isolated PHPStan PHAR is unavailable.'.PHP_EOL);
    exit(2);
}

Phar::loadPhar($phar, 'phpstan.phar');
require 'phar://phpstan.phar/bin/phpstan';
