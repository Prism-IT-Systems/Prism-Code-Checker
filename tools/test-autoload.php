<?php

function prismRegisterExtensionAutoload(string $source, array $prefixes): void
{
    if (! is_dir($source)) {
        echo 'dir not found: '.$source.PHP_EOL;

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

prismRegisterExtensionAutoload('vendor/iamcal/sql-parser/src', ['iamcal\\']);

$p = new iamcal\SQLParser();
echo get_class($p).PHP_EOL;

prismRegisterExtensionAutoload('vendor/larastan/larastan/src', ['Larastan\\Larastan\\']);

$r = new ReflectionClass('Larastan\\Larastan\\SQL\\IamcalSqlParser');
echo $r->getName().PHP_EOL;
echo 'OK'.PHP_EOL;
