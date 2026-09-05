<?php

namespace Banimark\Laravel\Console;

use Banimark\Tools\SqlTool;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * The support-ticket killer: one command that checks everything a broken
 * install usually turns out to be. Customers run this BEFORE writing in;
 * support asks for its output as the first reply.
 */
class DoctorCommand extends Command
{
    protected $signature = 'banimark:doctor';
    protected $description = 'Check the Banimark installation end to end';

    public function handle(): int
    {
        $bad = 0;
        $check = function (string $label, bool $ok, string $fix = '') use (&$bad) {
            $this->line(($ok ? '  ✔ ' : '  ✘ ').$label.(!$ok && $fix !== '' ? '  -> '.$fix : ''));
            if (!$ok) {
                $bad++;
            }
        };

        $this->info('Banimark doctor');

        // tables
        $tables = true;
        foreach (['banimark_conversations', 'banimark_messages', 'banimark_providers', 'banimark_rules', 'banimark_rule_folders', 'banimark_tools', 'banimark_agents', 'banimark_settings'] as $t) {
            try {
                DB::select('SELECT 1 FROM '.$t.' LIMIT 1');
            } catch (\Throwable $e) {
                $tables = false;
                break;
            }
        }
        $check('database tables', $tables, 'run: php artisan banimark:install');

        // provider
        $provider = null;
        try {
            $provider = DB::table('banimark_providers')->where('enabled', 1)->orderByDesc('is_default')->first();
        } catch (\Throwable $e) {
        }
        $configKey = (string) config('banimark.providers.'.config('banimark.default', 'gemini').'.api_key', '');
        $check('AI provider configured', $provider !== null || $configKey !== '', 'add one in the panel (AI Providers) or set the env key');
        if ($provider) {
            $check('provider "'.$provider->slug.'" has an API key', trim((string) $provider->api_key) !== '', 'edit it in the panel');
        }

        // identity secret
        $check('identity secret set', (string) config('banimark.identity_secret', '') !== '', 'php artisan banimark:install (generates it)');

        // licensing: on an ENCODED build the master file needs the ionCube (or
        // SourceGuardian) loader to run. We only flag it when the shipped file
        // is actually encoded, so plaintext dev/pilot installs stay quiet.
        // On a customer install the master sits at its canonical path (the
        // build flattens enc/ upward). Encoded = the readable method source is
        // gone; a plaintext placeholder still has it, so no loader is demanded.
        $master = __DIR__.'/../../Licensing/Master.php';
        $encoded = is_file($master) && !str_contains((string) @file_get_contents($master), 'public static function lock(');
        $loader = extension_loaded('ionCube Loader') || extension_loaded('ioncube')
            || function_exists('ioncube_loader_version') || extension_loaded('SourceGuardian');
        if ($encoded) {
            $check('ionCube loader (encoded license build)', $loader, 'enable the ionCube PHP loader on this host (zend_extension=ioncube_loader) - most commercial hosts have it');
        } else {
            $this->line('  - license master file is plaintext (unencoded build) - no loader needed');
        }
        $licKey = (string) (config('banimark.license.key') ?? env('BANIMARK_LICENSE_KEY', ''));
        try {
            $licKey = ($v = DB::table('banimark_settings')->where('key', 'license_key')->value('value')) !== null && $v !== '' ? (string) $v : $licKey;
            $licStatus = (string) (DB::table('banimark_settings')->where('key', 'license_status')->value('value') ?? '');
        } catch (\Throwable $e) {
            $licStatus = '';
        }
        if ($licKey === '') {
            $this->line('  - no license key set: the ADMIN panel is locked until one is entered (the widget/chat is unaffected)');
        } else {
            $this->line('  - license key set'.($licStatus !== '' ? ' (last status: '.$licStatus.')' : '').' - admin locks only on a confirmed expired/revoked/unknown status');
        }

        // routes
        $check('widget routes mounted', Route::has('banimark.chat') && Route::has('banimark.widget'), "config banimark.widget.routes must be true (or mount routes/widget.php yourself)");
        $check('admin panel mounted', Route::has('banimark.admin.inbox'), 'config banimark.admin.enabled must be true');

        // tools compile
        try {
            $rows = DB::table('banimark_tools')->where('enabled', 1)->get();
            foreach ($rows as $r) {
                try {
                    SqlTool::fromDefinition([
                        'name' => $r->name, 'description' => $r->description,
                        'parameters' => json_decode($r->parameters, true) ?: [],
                        'sql' => $r->sql, 'columns' => json_decode($r->columns, true) ?: [],
                        'context' => json_decode((string) $r->context, true) ?: [],
                        'max_rows' => (int) $r->max_rows,
                    ], fn () => []);
                    $check('tool "'.$r->name.'" compiles', true);
                } catch (\Throwable $e) {
                    $check('tool "'.$r->name.'" compiles', false, $e->getMessage());
                }
            }
            if ($rows->isEmpty()) {
                $this->line('  - no tools yet (the AI can chat but cannot look anything up)');
            }
        } catch (\Throwable $e) {
        }

        $this->line('');
        if ($bad === 0) {
            $this->info('All good. Embed the widget and talk to it.');
            return self::SUCCESS;
        }
        $this->error($bad.' problem(s) found - fixes suggested above.');
        return self::FAILURE;
    }
}
