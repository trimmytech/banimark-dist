<?php

namespace Banimark\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One command from `composer require` to a working chat:
 *   php artisan banimark:install
 * Publishes config, runs the package migrations, generates the identity
 * secret, optionally seeds the first AI provider, and prints the embed
 * snippet + panel URL. Idempotent - safe to re-run any time.
 */
class InstallCommand extends Command
{
    protected $signature = 'banimark:install {--no-provider : Skip the AI provider prompt}';
    protected $description = 'Install Banimark: config, tables, identity secret, first AI provider';

    public function handle(): int
    {
        $this->line('');
        $this->info('Banimark installer');
        $this->line('==================');

        // 1. config
        $this->callSilent('vendor:publish', ['--tag' => 'banimark-config', '--force' => false]);
        $this->line('✔ config published (config/banimark.php)');

        // 2. tables (migrate, then ensure - idempotent Schema::create adds any
        // tables introduced since the first install, e.g. staff accounts)
        $this->callSilent('migrate');
        \Banimark\Storage\Schema::create(\Illuminate\Support\Facades\DB::connection()->getPdo());
        (new \Banimark\Storage\Rules(\Illuminate\Support\Facades\DB::connection()->getPdo()))->seedDefaults();
        $this->line('✔ tables migrated (banimark_*)');

        // 3. identity secret
        if ((string) config('banimark.identity_secret', '') === '') {
            $secret = bin2hex(random_bytes(32));
            if ($this->writeEnv('BANIMARK_IDENTITY_SECRET', $secret)) {
                config(['banimark.identity_secret' => $secret]);
                $this->line('✔ identity secret generated (BANIMARK_IDENTITY_SECRET in .env)');
            } else {
                $this->warn('! could not write .env - add BANIMARK_IDENTITY_SECRET='.$secret.' yourself');
            }
        } else {
            $this->line('✔ identity secret already set');
        }

        // 3b. first owner account (Banimark's own staff login)
        $agents = new \Banimark\Auth\Agents(\Illuminate\Support\Facades\DB::connection()->getPdo());
        if ($agents->count() === 0) {
            if (!$this->input->isInteractive()) {
                $this->warn('! no admin account - run: php artisan banimark:agent (or open the panel once interactively)');
            } else {
                $email = (string) $this->ask('Admin email (your panel login)');
                $pass = (string) $this->secret('Admin password (min 8 chars, hidden)');
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($pass) >= 8) {
                    $agents->create((string) $this->ask('Your name', 'Owner'), $email, $pass, 'owner');
                    $this->line('✔ owner account created ('.$email.')');
                } else {
                    $this->warn('! invalid email/password - no admin account created');
                }
            }
        } else {
            $this->line('✔ admin account already exists');
        }

        // 4. first provider (interactive, skippable)
        $hasProvider = false;
        try {
            $hasProvider = DB::table('banimark_providers')->where('enabled', 1)->exists();
        } catch (\Throwable $e) {
        }
        if ($hasProvider) {
            $this->line('✔ AI provider already configured');
        } elseif ($this->option('no-provider') || !$this->input->isInteractive()) {
            $this->warn('! no AI provider yet - add one in the panel (AI Providers) or via config/env');
        } else {
            $this->seedProvider();
        }

        // escalation defaults
        foreach (['escalation_mode' => 'staff', 'escalation_email' => ''] as $k => $v) {
            \Illuminate\Support\Facades\DB::table('banimark_settings')->updateOrInsert(['key' => $k], ['value' => $v]);
        }

        // 5. hand-over
        $this->line('');
        $this->info('Done. Next steps:');
        $this->line('  1. Panel:  '.url((string) config('banimark.admin.prefix', 'banimark/admin')).'  (Banimark staff login - not your app\'s auth)');
        $this->line('  2. Build your first tool there (Tools), write a rule or two (Rules).');
        $this->line('  3. Embed the widget:');
        $this->line('     <script src="'.url('/banimark/widget.js').'" defer></script>');
        $this->line('     Logged-in users (lets tools scope to the user):');
        $this->line("     <script src=\"".url('/banimark/widget.js')."\" defer");
        $this->line("             data-token=\"{{ \\Banimark\\Identity\\VisitorToken::mint(['user_id' => auth()->id()], config('banimark.identity_secret')) }}\"></script>");
        $this->line('  4. Health check any time:  php artisan banimark:doctor');
        $this->line('');

        return self::SUCCESS;
    }

    private function seedProvider(): void
    {
        $presets = [
            'gemini' => ['driver' => 'gemini', 'model' => 'gemini-2.5-flash', 'base_url' => null],
            'deepseek' => ['driver' => 'openai-compat', 'model' => 'deepseek-chat', 'base_url' => 'https://api.deepseek.com'],
            'openai' => ['driver' => 'openai-compat', 'model' => 'gpt-4o-mini', 'base_url' => 'https://api.openai.com/v1'],
            'anthropic' => ['driver' => 'anthropic', 'model' => 'claude-sonnet-5', 'base_url' => null],
            'skip' => null,
        ];
        $choice = $this->choice('Which AI provider will answer the chat?', array_keys($presets), 0);
        if ($choice === 'skip' || $presets[$choice] === null) {
            $this->warn('! skipped - add a provider in the panel before going live');
            return;
        }
        $key = (string) $this->secret('API key for '.$choice.' (input hidden)');
        if (trim($key) === '') {
            $this->warn('! no key entered - add the provider in the panel later');
            return;
        }
        $model = (string) $this->ask('Model', $presets[$choice]['model']);
        DB::table('banimark_providers')->updateOrInsert(['slug' => $choice], [
            'driver' => $presets[$choice]['driver'],
            'api_key' => trim($key),
            'model' => $model !== '' ? $model : $presets[$choice]['model'],
            'base_url' => $presets[$choice]['base_url'],
            'temperature' => 0.4,
            'enabled' => true,
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->line('✔ provider "'.$choice.'" saved as default');
    }

    private function writeEnv(string $key, string $value): bool
    {
        $path = base_path('.env');
        if (!is_file($path) || !is_writable($path)) {
            return false;
        }
        $env = (string) file_get_contents($path);
        if (preg_match('/^'.preg_quote($key, '/').'=.*$/m', $env)) {
            $env = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $env);
        } else {
            $env = rtrim($env, "\n")."\n".$key.'='.$value."\n";
        }
        return file_put_contents($path, $env) !== false;
    }
}
