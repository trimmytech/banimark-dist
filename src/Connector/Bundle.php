<?php

namespace Banimark\Connector;

/**
 * The connector downloads, generated for ONE desk: its secret and its name
 * are written into the files, so the owner installs and pastes an address
 * back - nothing to type, nothing to configure. The templates live in
 * resources/connector/<platform>/ and are the reference implementations the
 * conformance checker (build/check-connector.php) proves.
 */
final class Bundle
{
    public const SECRET_PLACEHOLDER = '__BANIMARK_SECRET__';
    public const DESK_PLACEHOLDER = '__BANIMARK_DESK__';

    /**
     * The platforms, in the order the Tools page shows them. `how` is the one-line
     * version; `needs` and `steps` are the tab on the Tools page (a step is text,
     * or text plus a code block to copy).
     *
     * @return array<string, array{label: string, tab: string, file: string, how: string, zip: bool, needs: string, address: string, steps: list<array{0: string, 1?: string}>}>
     */
    public static function platforms(): array
    {
        return [
            'wordpress' => ['label' => 'WordPress / WooCommerce', 'tab' => 'WordPress', 'file' => 'banimark-connector.zip', 'zip' => true,
                'how' => 'Plugins → Add New → Upload Plugin → choose the zip → Activate. Then Settings → Banimark connector shows the address.',
                'needs' => 'WordPress 5.6 or newer on PHP 7.4 or newer. It reads the database wp-config.php already points at - nothing to configure.',
                'address' => 'https://yoursite.com/wp-json/banimark/v1/connector',
                'steps' => [
                    ['In your WordPress admin go to Plugins → Add New → Upload Plugin, choose banimark-connector.zip, press Install Now, then Activate.'],
                    ['Open Settings → Banimark connector. The page says the connector is installed and shows its address.'],
                    ['Copy that address into the box below, press Save, then Test the connection.'],
                ]],
            'php' => ['label' => 'Plain PHP website', 'tab' => 'Plain PHP', 'file' => 'banimark-connector.php', 'zip' => false,
                'how' => 'Upload the file next to your site\'s index.php, then open it in your browser: it shows the address. Fill in the four database lines at the top if it asks.',
                'needs' => 'Any PHP 7.4 or newer host with PDO (cPanel, Plesk, a plain server). MySQL, MariaDB, PostgreSQL or SQLite.',
                'address' => 'https://yoursite.com/banimark-connector.php',
                'steps' => [
                    ['Upload banimark-connector.php next to your site\'s index.php, with FTP or your host\'s file manager.'],
                    ['Open it in your browser. If the page says the database is not reachable yet, open the file and fill in the three lines near the top (a WordPress site is found on its own):',
                        "const BANIMARK_DB_DSN = 'mysql:host=127.0.0.1;dbname=shop';\nconst BANIMARK_DB_USER = 'shop_readonly';\nconst BANIMARK_DB_PASS = 'the password';"],
                    ['The page shows the address. Copy it into the box below, press Save, then Test the connection.'],
                ]],
            'laravel' => ['label' => 'Laravel', 'tab' => 'Laravel', 'file' => 'banimark-connector-laravel.zip', 'zip' => true,
                'how' => 'Copy the controller into app/Http/Controllers, the route file into routes/, and add one line to routes/api.php (README inside). The address is /api/banimark-connector.',
                'needs' => 'Laravel 9 or newer. It uses your app\'s default database connection.',
                'address' => 'https://yoursite.com/api/banimark-connector',
                'steps' => [
                    ['Unzip it. Copy app/Http/Controllers/BanimarkConnectorController.php into your app\'s app/Http/Controllers/, and routes/banimark-connector.php into routes/.'],
                    ['Add one line at the bottom of routes/api.php (Laravel 11 or newer: run php artisan install:api first if routes/api.php does not exist):',
                        "require __DIR__.'/banimark-connector.php';"],
                    ['Deploy, then open https://yoursite.com/api/banimark-connector in a browser: it shows the address. Copy it into the box below, press Save, then Test the connection.'],
                ]],
            'node' => ['label' => 'Node.js', 'tab' => 'Node.js', 'file' => 'banimark-connector.js', 'zip' => false,
                'how' => 'One file, no package. Run it with node, or mount it in your Express app (comments inside). Needs your database driver (mysql2, pg) or Node\'s built-in SQLite.',
                'needs' => 'Node 18 or newer, no package to install. It uses the driver your app already has: mysql2, pg, or Node\'s built-in SQLite.',
                'address' => 'https://yoursite.com/banimark-connector',
                'steps' => [
                    ['Put banimark-connector.js in your project.'],
                    ['Inside Express, mount it before express.json() - the request is signed over the raw body:',
                        "app.post('/banimark-connector', require('./banimark-connector').express());\napp.get('/banimark-connector',  require('./banimark-connector').express());"],
                    ['Or run it on its own behind your reverse proxy (it listens on PORT, default 8787):',
                        "BANIMARK_DB_URL=postgres://user:password@127.0.0.1/shop node banimark-connector.js"],
                    ['Deploy, then open https://yoursite.com/banimark-connector in a browser: it shows the address. Copy it into the box below, press Save, then Test the connection.'],
                ]],
            'python' => ['label' => 'Python (FastAPI, Flask, Django)', 'tab' => 'Python', 'file' => 'banimark_connector.py', 'zip' => false,
                'how' => 'One file. Drop it next to your app and add one line: FastAPI app.include_router(fastapi_router(engine)), Flask or Django views for the others (comments inside), or run it on its own with python. Uses the SQLAlchemy and driver your app already has (PostgreSQL, MySQL, SQLite).',
                'needs' => 'Python 3.8 or newer with SQLAlchemy 1.4 or 2.x and the driver your app already uses (psycopg2 for PostgreSQL, PyMySQL for MySQL, SQLite built in). No other package.',
                'address' => 'https://api.yoursite.com/banimark-connector',
                'steps' => [
                    ['Put banimark_connector.py next to your app, where main.py or app.py is.'],
                    ['FastAPI: two lines, with the SQLAlchemy engine your app already made. The connector answers at /banimark-connector.',
                        "from banimark_connector import fastapi_router\napp.include_router(fastapi_router(engine))"],
                    ['Flask or Django instead:',
                        "app.add_url_rule('/banimark-connector', 'banimark', flask_view(engine), methods=['GET', 'POST'])   # Flask\npath('banimark-connector', django_view(engine))                                                  # Django, in urls.py"],
                    ['Or run it on its own behind your reverse proxy (it listens on PORT, default 8787):',
                        "BANIMARK_DB_URL=postgresql://user:password@127.0.0.1/shop python banimark_connector.py"],
                    ['Deploy, then open https://api.yoursite.com/banimark-connector in a browser: it shows the address. Copy it into the box below, press Save, then Test the connection.'],
                ]],
        ];
    }

    /**
     * The download: filename, bytes, content type.
     *
     * @throws \InvalidArgumentException for an unknown platform, \RuntimeException when it cannot be built
     * @return array{name: string, bytes: string, mime: string}
     */
    public static function build(string $platform, string $secret, string $desk): array
    {
        $p = self::platforms()[$platform] ?? null;
        if ($p === null) {
            throw new \InvalidArgumentException('No connector for "'.$platform.'".');
        }
        if (!preg_match('/^[a-f0-9]{48}$/', $secret)) {
            throw new \RuntimeException('The connector secret has not been made yet.');
        }
        $root = \Banimark\Ui\Assets::root().'/resources/connector/'.$platform;
        if (!is_dir($root)) {
            throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
        }
        $fill = fn (string $text) => str_replace([self::SECRET_PLACEHOLDER, self::DESK_PLACEHOLDER], [$secret, self::deskName($desk)], $text);
        if (!$p['zip']) {
            $file = $root.'/'.$p['file'];
            if (!is_file($file)) {
                throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
            }
            return ['name' => $p['file'], 'bytes' => $fill((string) file_get_contents($file)), 'mime' => 'application/octet-stream'];
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('This server cannot make zip files (PHP\'s zip extension is missing). Ask your host to enable it, or download the plain PHP connector instead.');
        }
        $files = self::files($root);
        if ($files === []) {
            throw new \RuntimeException('The '.$p['label'].' connector is not part of this build.');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'bmc');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not build the zip.');
        }
        foreach ($files as $path) {
            $zip->addFromString(substr($path, strlen($root) + 1), $fill((string) file_get_contents($path)));
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return ['name' => $p['file'], 'bytes' => $bytes, 'mime' => 'application/zip'];
    }

    /** Are this platform's files in the tree? (A download of a missing one answers 404 with the reason.) */
    public static function available(string $platform): bool
    {
        $root = \Banimark\Ui\Assets::root().'/resources/connector/'.$platform;
        return is_dir($root) && self::files($root) !== [];
    }

    /** The desk name as it may appear inside a generated source file: no quotes, no line breaks, short. */
    public static function deskName(string $desk): string
    {
        $clean = trim((string) preg_replace('/[\'"\\\\`$\r\n\t<>]+/', '', $desk));
        return $clean !== '' ? mb_substr($clean, 0, 60) : 'your Banimark desk';
    }

    /** @return string[] every file under $dir, deterministic order */
    private static function files(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = (string) $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}
