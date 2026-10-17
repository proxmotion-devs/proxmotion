<?php

declare(strict_types=1);

/*
 * WISECP does not autoload a module's own classes: load Proxmotion\Wisecp\*
 * from this directory.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Proxmotion\\Wisecp\\';

    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require_once $file;
        }
    }
});
