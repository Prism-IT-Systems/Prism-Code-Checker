<?php

declare(strict_types=1);

// PHPStan normally discovers Prism's Composer autoloader because its PHAR
// lives under Prism/vendor. That also loads Laravel's global helpers, whose
// config() function collides with CodeIgniter's. Run a copied PHAR and load
// only the scanned project plus the official framework extensions.

$projectAutoload = getenv('PRISM_PROJECT_AUTOLOAD');

if (is_string($projectAutoload) && is_file($projectAutoload)) {
    require_once $projectAutoload;
}

/**
 * @param  array<int, string>  $prefixes
 */
function prismRegisterExtensionAutoload(string $source, array $prefixes): void
{
    if (! is_dir($source)) {
        return;
    }

    spl_autoload_register(static function (string $class) use ($source, $prefixes): void {
        foreach ($prefixes as $prefix) {
            if (! str_starts_with($class, $prefix)) {
                continue;
            }

            $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';
            $file = rtrim($source, '/\\').DIRECTORY_SEPARATOR.$relative;

            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    });
}

prismRegisterExtensionAutoload(
    (string) getenv('PRISM_CI_PHPSTAN_SOURCE'),
    ['CodeIgniter\\PHPStan\\']
);
prismRegisterExtensionAutoload(
    (string) getenv('PRISM_LARASTAN_SOURCE'),
    ['Larastan\\Larastan\\']
);
prismRegisterExtensionAutoload(
    (string) getenv('PRISM_SQL_PARSER_SOURCE'),
    ['iamcal\\']
);
prismRegisterExtensionAutoload(
    (string) getenv('PRISM_WP_PHPSTAN_SOURCE'),
    ['SzepeViktor\\PHPStan\\WordPress\\']
);

$phar = getenv('PRISM_PHPSTAN_PHAR');

if (! is_string($phar) || ! is_file($phar)) {
    fwrite(STDERR, 'The isolated PHPStan PHAR is unavailable.'.PHP_EOL);
    exit(2);
}

Phar::loadPhar($phar, 'phpstan.phar');
require 'phar://phpstan.phar/bin/phpstan';
