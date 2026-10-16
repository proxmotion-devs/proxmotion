<?php

/*
 * Class loader of the module: WHMCS has no Composer autoloading for modules.
 * Proxmotion\Whmcs\Foo lives in lib/Foo.php.
 */

spl_autoload_register(static function ($class) {
    $prefix = 'Proxmotion\\Whmcs\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
