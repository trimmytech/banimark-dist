<?php

namespace Banimark\Standalone;

use Banimark\Auth\Agents;
use Banimark\Storage\Schema;

/**
 * The browser installer for non-Laravel hosts: requirements check, database
 * connection, schema creation, admin password + identity secret, optional
 * first AI provider - then it locks itself (an `installed` flag in settings
 * plus the written config file). Re-running against an installed database
 * shows a locked page, never a reinstall.
 */
class Installer
{
    /** @return array<int, array{label: string, ok: bool, required: bool, hint: string}> */
    public static function requirements(?string $configDir = null): array
    {
        $rows = [];
        $rows[] = ['label' => 'PHP >= 8.1 (found '.PHP_VERSION.')', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'required' => true, 'hint' => 'Upgrade PHP.'];
        foreach (['json', 'curl', 'pdo'] as $ext) {
            $rows[] = ['label' => "ext-{$ext}", 'ok' => extension_loaded($ext), 'required' => true, 'hint' => "Install the {$ext} PHP extension."];
        }
        $drivers = class_exists(\PDO::class) ? \PDO::getAvailableDrivers() : [];
        $rows[] = ['label' => 'a PDO driver (found: '.(implode(', ', $drivers) ?: 'none').')', 'ok' => (bool) array_intersect($drivers, ['mysql', 'sqlite']), 'required' => true, 'hint' => 'Install pdo_mysql or pdo_sqlite.'];
        if ($configDir !== null) {
            $rows[] = ['label' => 'config directory writable ('.$configDir.')', 'ok' => is_dir($configDir) && is_writable($configDir), 'required' => true, 'hint' => 'chmod the directory so the installer can write banimark.config.php.'];
        }
        $rows[] = ['label' => 'HTTPS', 'ok' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off'), 'required' => false, 'hint' => 'Strongly recommended before going live.'];
        // Licensed builds ship the master license file encoded; the loader runs
        // it. Not required on dev/plaintext builds, so this is advisory - but a
        // customer whose host lacks it would get a blank Engine page.
        $rows[] = ['label' => 'ionCube loader (for licensed builds)', 'ok' => self::ioncubeLoaded(), 'required' => false, 'hint' => 'Ask your host to enable the ionCube PHP loader (most commercial hosts have it; otherwise add zend_extension=ioncube_loader to php.ini).'];
        return $rows;
    }

    /** True if an ionCube (or SourceGuardian) loader is active. */
    public static function ioncubeLoaded(): bool
    {
        return extension_loaded('ionCube Loader') || extension_loaded('ioncube')
            || function_exists('ioncube_loader_version') || extension_loaded('SourceGuardian');
    }

    public static function requirementsMet(?string $configDir = null): bool
    {
        foreach (self::requirements($configDir) as $r) {
            if ($r['required'] && !$r['ok']) {
                return false;
            }
        }
        return true;
    }

    /**
     * Execute the installation. Returns ['ok' => bool, 'error' => ?string].
     *
     * @param array $input driver, host, port, database, username, password,
     *                     sqlite_path, admin_password, provider_slug,
     *                     provider_key, provider_model, site_title
     */
    public static function install(array $input, string $configPath): array
    {
        if (!self::requirementsMet(dirname($configPath))) {
            return ['ok' => false, 'error' => 'Server requirements are not met.'];
        }
        $adminPassword = (string) ($input['admin_password'] ?? '');
        $adminEmail = strtolower(trim((string) ($input['admin_email'] ?? '')));
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Enter a valid admin email - it is your login.'];
        }
        if (strlen($adminPassword) < 8) {
            return ['ok' => false, 'error' => 'Choose an admin password of at least 8 characters.'];
        }

        // 1. connect
        try {
            [$dsn, $user, $pass] = self::dsn($input);
            $pdo = new \PDO($dsn, $user, $pass, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Could not connect to the database: '.$e->getMessage()];
        }

        // 2. refuse to reinstall over a live install
        try {
            $st = $pdo->query("SELECT `value` FROM banimark_settings WHERE `key` = 'installed'");
            if ($st !== false && $st->fetchColumn() === '1') {
                return ['ok' => false, 'error' => 'Banimark is already installed on this database.'];
            }
        } catch (\Throwable $e) {
            // table absent = fresh database, carry on
        }

        // 3. schema + seed settings
        try {
            Schema::create($pdo);
            $settings = new Settings($pdo);
            (new Agents($pdo))->create((string) ($input['admin_name'] ?? 'Owner'), $adminEmail, $adminPassword, 'owner');
            $settings->set('identity_secret', bin2hex(random_bytes(32)));
            $settings->set('escalation_mode', 'staff');
            $settings->set('escalation_email', '');
            $settings->set('title', trim((string) ($input['site_title'] ?? '')) ?: 'Support');
            $settings->set('greeting', 'Hi! How can we help you today?');
            $settings->set('color', '#6F04D9');
            $settings->set('position', 'right');

            // 4. optional first provider
            $slug = strtolower(trim((string) ($input['provider_slug'] ?? '')));
            $key = trim((string) ($input['provider_key'] ?? ''));
            if ($slug !== '' && $slug !== 'skip' && $key !== '') {
                $presets = [
                    'gemini' => ['gemini', 'gemini-2.5-flash', null],
                    'deepseek' => ['openai-compat', 'deepseek-chat', 'https://api.deepseek.com'],
                    'openai' => ['openai-compat', 'gpt-4o-mini', 'https://api.openai.com/v1'],
                    'anthropic' => ['anthropic', 'claude-sonnet-5', null],
                ];
                if (isset($presets[$slug])) {
                    [$driver, $defModel, $baseUrl] = $presets[$slug];
                    $model = trim((string) ($input['provider_model'] ?? '')) ?: $defModel;
                    $st = $pdo->prepare('INSERT INTO banimark_providers (slug, driver, api_key, model, base_url, temperature, enabled, is_default, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0.4, 1, 1, ?, ?)');
                    $st->execute([$slug, $driver, $key, $model, $baseUrl, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
                }
            }

            $settings->set('installed', '1');
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Installation failed: '.$e->getMessage()];
        }

        // 5. write the bootstrap config the front controller reads
        $config = "<?php\n\nreturn ".var_export([
            'dsn' => $dsn,
            'db_user' => $user,
            'db_pass' => $pass,
            'prefix' => 'banimark_',
        ], true).";\n";
        if (@file_put_contents($configPath, $config) === false) {
            return ['ok' => false, 'error' => 'Installed the database but could not write '.$configPath.' - create it manually with your DB credentials.'];
        }

        return ['ok' => true, 'error' => null];
    }

    /** @return array{0: string, 1: ?string, 2: ?string} */
    private static function dsn(array $input): array
    {
        if (($input['driver'] ?? '') === 'sqlite') {
            $path = (string) ($input['sqlite_path'] ?? '');
            if ($path === '') {
                throw new \InvalidArgumentException('SQLite path is required.');
            }
            return ['sqlite:'.$path, null, null];
        }
        $host = (string) ($input['host'] ?? '127.0.0.1');
        $port = (int) ($input['port'] ?? 3306);
        $db = (string) ($input['database'] ?? '');
        if ($db === '') {
            throw new \InvalidArgumentException('Database name is required.');
        }
        return [
            "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
            (string) ($input['username'] ?? ''),
            (string) ($input['password'] ?? ''),
        ];
    }
}
