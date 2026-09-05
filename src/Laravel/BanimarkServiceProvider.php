<?php

namespace Banimark\Laravel;

use Banimark\AiManager;
use Illuminate\Support\ServiceProvider;

class BanimarkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/banimark.php', 'banimark');

        $this->app->singleton(AiManager::class, function ($app) {
            return new AiManager((array) $app['config']->get('banimark', []));
        });

        $this->app->singleton(\Banimark\Storage\PdoStore::class, function ($app) {
            return new \Banimark\Storage\PdoStore($app['db']->connection()->getPdo());
        });
        $this->app->bind(\Banimark\Contracts\StateStore::class, \Banimark\Storage\PdoStore::class);

        $this->app->singleton(\Banimark\Auth\Agents::class, function ($app) {
            return new \Banimark\Auth\Agents($app['db']->connection()->getPdo());
        });
        $this->app->singleton(\Banimark\Auth\AgentAuth::class, function ($app) {
            // license status provider: reads the panel-entered key first, then
            // env/config (survives a stale published config). The verdict is
            // computed by the encoded Master inside AgentAuth->check().
            $status = function () {
                try {
                    $s = \Illuminate\Support\Facades\DB::table('banimark_settings')
                        ->whereIn('key', ['license_key', 'license_token'])->pluck('value', 'key')->all();
                } catch (\Throwable $e) {
                    $s = [];
                }
                $key = ($s['license_key'] ?? '') !== '' ? $s['license_key']
                    : (config('banimark.license.key') ?? env('BANIMARK_LICENSE_KEY', ''));
                return [
                    'key' => (string) $key,
                    'token' => $s['license_token'] ?? null,
                    // the host we are actually running on - a token signed for
                    // another site must not unlock this one
                    'host' => (string) (request()?->getHost() ?? ''),
                    'module' => \Banimark\Licensing\Master::MODULE_DESK,
                ];
            };
            return new \Banimark\Auth\AgentAuth($app->make(\Banimark\Auth\Agents::class), new \Banimark\Auth\LaravelSession(), $status);
        });

        $this->app->bind(\Banimark\Http\HistoryEndpoint::class, function ($app) {
            return new \Banimark\Http\HistoryEndpoint(
                $app->make(\Banimark\Storage\PdoStore::class),
                (string) config('banimark.identity_secret', ''),
            );
        });
        // the mailer follows the panel's SMTP settings, falling back to mail()
        $this->app->bind(\Banimark\Notify\Mailer::class, function () {
            return \Banimark\Notify\MailerFactory::make(self::settings());
        });

        $this->app->bind(\Banimark\Http\ChatEndpoint::class, function ($app) {
            return new \Banimark\Http\ChatEndpoint(
                \Banimark\Laravel\EngineFactory::make(),
                $app->make(\Banimark\Storage\PdoStore::class),
                (string) config('banimark.identity_secret', ''),
                2000, 40,
                // escalation alert: Banimark's OWN mailer (panel SMTP settings),
                // so the host app's mail config is neither required nor touched
                new \Banimark\Notify\CallbackNotifier(function ($sessionId, $label, $reason) use ($app) {
                    $settings = self::settings();
                    if (($settings['escalation_mode'] ?? 'staff') !== 'email') { return; }
                    $to = array_filter(array_map('trim', explode(',', (string) ($settings['escalation_email'] ?? ''))));
                    if (!$to) { $to = $app->make(\Banimark\Auth\Agents::class)->emails(); }
                    if (!$to) { return; }
                    try {
                        $app->make(\Banimark\Notify\Mailer::class)->send(
                            $to,
                            'New escalation from '.$label,
                            "A visitor asked for a human.\n\nWho: {$label}\nReason: {$reason}\n\nOpen the panel to reply."
                        );
                    } catch (\Throwable $e) { /* mail must never break the reply */ }
                }),
            );
        });
        $this->app->bind(\Banimark\Http\PollEndpoint::class, function ($app) {
            return new \Banimark\Http\PollEndpoint(
                $app->make(\Banimark\Storage\PdoStore::class),
                (string) config('banimark.identity_secret', ''),
            );
        });
    }

    /** @return array<string, string> the settings table, or [] before migration */
    public static function settings(): array
    {
        try {
            return \Illuminate\Support\Facades\DB::table('banimark_settings')->pluck('value', 'key')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function boot(): void
    {
        // widget routes: chat endpoint + configured widget script. Hosts that
        // want custom paths/middleware skip this via config and mount their own.
        if (config('banimark.widget.routes', true)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/widget.php');
        }
        if (class_exists(\Illuminate\Support\Facades\RateLimiter::class)) {
            \Illuminate\Support\Facades\RateLimiter::for('banimark-chat', function ($request) {
                return \Illuminate\Cache\RateLimiting\Limit::perMinute(
                    (int) config('banimark.widget.rate_per_minute', 20)
                )->by($request->ip());
            });
        }
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'banimark');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        // admin panel - host controls access via banimark.admin.middleware
        if (config('banimark.admin.enabled', true)) {
            // ALWAYS 'web' (session + CSRF) + any host opt-in extras. We do
            // NOT read a published 'middleware' key: an old install that
            // published config/banimark.php with ['web','auth'] would keep
            // bouncing to the host's own login forever. Banimark has its OWN
            // staff gate now, so 'auth' must never be forced here. Hosts who
            // want extra restriction add banimark.admin.extra_middleware.
            $middleware = array_values(array_unique(array_merge(
                ['web'],
                (array) config('banimark.admin.extra_middleware', []),
                // the single auth+licence gate for the whole panel - no
                // controller method can bypass or forget it
                [\Banimark\Laravel\Http\Middleware\EnsureBanimarkAccess::class],
            )));
            // panel CSS/JS as same-origin files - OUTSIDE the gate: the login page
            // needs them before any session exists, and they carry no secrets.
            // A customer's Content-Security-Policy ('self') allows these where
            // it blocks every inline block and onclick= attribute.
            \Illuminate\Support\Facades\Route::get(
                (string) config('banimark.admin.prefix', 'banimark/admin').'/assets/{name}',
                [\Banimark\Laravel\Admin\PanelController::class, 'asset']
            )->where('name', '[a-z]+\\.(css|js)')->name('banimark.admin.asset');

            \Illuminate\Support\Facades\View::composer('banimark::admin.*', function () {
                \Banimark\Ui\Layout::configure([
                    'assets' => url((string) config('banimark.admin.prefix', 'banimark/admin').'/assets'),
                ]);
            });

            \Illuminate\Support\Facades\Route::group([
                'prefix' => (string) config('banimark.admin.prefix', 'banimark/admin'),
                'middleware' => $middleware,
            ], function () {
                $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
            });
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Banimark\Laravel\Console\InstallCommand::class,
                \Banimark\Laravel\Console\DoctorCommand::class,
                \Banimark\Laravel\Console\AgentCommand::class,
            ]);
            $this->publishes([
                __DIR__.'/../../config/banimark.php' => $this->app->configPath('banimark.php'),
            ], 'banimark-config');
        }
    }
}
