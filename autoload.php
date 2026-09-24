<?php

/**
 * Banimark WITHOUT Composer: require this file instead of vendor/autoload.php.
 *
 *     require __DIR__.'/../banimark/autoload.php';
 *
 * It does for this one package exactly what Composer's autoloader does, and
 * nothing else - Banimark needs no other package at runtime (PHP 8.2, ext-curl
 * and ext-json are the whole list):
 *
 *  1. a Banimark class name maps to the same path under src/ - the readable files that ship
 *     beside the core;
 *  2. src/core_boot.php loads the core itself (src/Core.php, encoded) on the
 *     first Banimark class that is not one of those files - and, when the
 *     server has no ionCube Loader, shows the page saying what to install
 *     instead of letting PHP fail.
 *
 * The order matters and is the same as Composer's: the file map first, the
 * core second, so the core is only ever reached for a class that is not a file.
 *
 * Safe beside Composer too: require_once on the SAME core_boot.php path
 * Composer loads, so the core loader is never registered twice.
 */
spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'Banimark\\', 9) !== 0) {
        return;
    }
    $file = __DIR__.'/src/'.str_replace('\\', '/', substr($class, 9)).'.php';
    if (is_file($file)) {
        require $file;
    }
});

require_once __DIR__.'/src/core_boot.php';
