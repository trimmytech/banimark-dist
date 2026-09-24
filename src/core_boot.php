<?php

/**
 * How a CUSTOMER install finds the core.
 *
 * The dev tree uses core_loader.php instead, and build/release.sh deletes it
 * from the dist: it prefers a plaintext Core.php when one exists, and a dist
 * must never prefer a file someone could drop in beside the encoded one. This
 * loads the encoded core - src/Core.php, or the src/Core-X.Y.php built for a
 * newer PHP when this PHP runs that one (CoreHealth::coreFile) - and nothing else.
 *
 * Why not Composer's classmap, which the dist used to rely on: Composer builds
 * a classmap by READING the class declarations out of a file, and an encoded
 * file has none it can see - measured with `composer dump-autoload`: 38 classes
 * found in the readable core, 0 in the encoded one. Every install would have
 * died with "class not found". This is registered through Composer's `files`
 * autoload instead, so it runs after Composer's PSR-4 loader has missed.
 *
 * Installs upgraded in place (one-click update, no `composer dump-autoload`)
 * keep their OLD classmap, which still maps every class to src/Core.php - the
 * same path - so they load the new core too. Both routes end at one file.
 */
spl_autoload_register(static function (string $class): void {
    static $loaded = false;
    if ($loaded || strncmp($class, 'Banimark\\', 9) !== 0) {
        return;
    }
    // By path, not through an autoloader: this runs INSIDE one, and must not
    // depend on which classmap an install upgraded in place still carries.
    if (!class_exists(\Banimark\CoreHealth::class, false) && is_file(__DIR__.'/CoreHealth.php')) {
        require_once __DIR__.'/CoreHealth.php';
    }
    $health = class_exists(\Banimark\CoreHealth::class, false);
    // one encode per target PHP may ship; take the one THIS PHP's loader runs
    $core = $health ? \Banimark\CoreHealth::coreFile(__DIR__) : __DIR__.'/Core.php';
    if (!is_file($core)) {
        return;   // not ours to explain; PHP reports the class as missing
    }

    // An encoded core must never be included when it cannot run. Without the
    // loader, a licensed encode's stub prints ionCube's own page and calls
    // exit(), and a TRIAL encode has no check at all - it prints its encoded
    // payload straight into the response (seen live on the pilot). With the
    // loader but the wrong PHP (a server upgraded to a PHP the build predates,
    // an update built for a newer PHP) the loader stops PHP with a FATAL error
    // no try/catch can see. So CoreHealth decides first.
    $head = (string) @file_get_contents($core, false, null, 0, 512);
    $encoded = !str_contains($head, 'BANIMARK CORE - generated');
    $problem = null;
    if ($encoded && $health) {
        $problem = \Banimark\CoreHealth::problem(__DIR__);
    } elseif ($encoded && !extension_loaded('ionCube Loader')) {
        $problem = ['reason' => 'loader'];   // no CoreHealth to ask: the one check that needs nothing
    }
    if ($problem !== null) {
        // The standalone runtime IS the page - nothing above it will catch an
        // exception and turn it into something a person can act on - so show
        // the fix here. Under Laravel the service provider has already answered
        // (CoreUnavailable), and anything reaching this point is a host
        // script's own problem, so it gets the exception.
        if ($health && PHP_SAPI !== 'cli' && !class_exists('Illuminate\\Foundation\\Application', false)) {
            $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json');   // the widget
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: '.($json ? 'application/json' : 'text/html; charset=utf-8'));
                header('Cache-Control: no-store');
            }
            echo $json ? json_encode(\Banimark\CoreHealth::forVisitor()) : \Banimark\CoreHealth::page($problem);
            exit;
        }
        throw new \RuntimeException($problem['reason'] === 'loader'
            ? 'Banimark needs the free ionCube Loader for PHP '.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION
                .' to run its core. Install it from https://www.ioncube.com/loaders.php (or ask your host to),'
                .' then reload. Nothing in your database was touched.'
            : 'Banimark cannot load its core: '.$problem['title'].'. '.$problem['message']);
    }

    $loaded = true;   // before the require: a broken file must not be retried on every class

    // What CoreHealth cannot know in advance - a PHP newer than every loader
    // we have seen - is learned the one way there is: the loader refuses the
    // file, fatally. A shutdown function still runs after that fatal
    // (measured: error_get_last() holds the loader's message), so it is
    // remembered, and every later request gets the page instead of the fatal.
    if ($encoded && $health) {
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e === null || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)
                || !\Banimark\CoreHealth::isLoaderRefusal((string) $e['message'])
                || class_exists('Banimark\\Engine\\Engine', false)) {   // the core loaded; not ours
                return;
            }
            \Banimark\CoreHealth::recordRefusal(__DIR__, (string) $e['message']);
            if (PHP_SAPI === 'cli' || headers_sent() || ($problem = \Banimark\CoreHealth::problem(__DIR__)) === null) {
                return;   // a framework already answered (Laravel renders its own fatal page)
            }
            http_response_code(503);
            header('Cache-Control: no-store');
            if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'json')) {
                header('Content-Type: application/json');
                echo json_encode(\Banimark\CoreHealth::forVisitor());
            } else {
                header('Content-Type: text/html; charset=utf-8');
                echo \Banimark\CoreHealth::page($problem);
            }
        });
    }
    require $core;
});
