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
                15,
                $app->make(\Banimark\Storage\Attachments::class),
            );
        });

        /* ---------------- files shared in a chat ---------------- */
        $this->app->singleton(\Banimark\Storage\Attachments::class, function () {
            return new \Banimark\Storage\Attachments(\Illuminate\Support\Facades\DB::connection()->getPdo());
        });
        // the store is READABLE in the shipped build on purpose: a customer can
        // point it at their own storage without waiting for a release
        $this->app->bind(\Banimark\Files\FileStore::class, function () {
            return \Banimark\Files\FileStoreFactory::make(self::settings(), storage_path('app/banimark-files'));
        });
        $this->app->bind(\Banimark\Http\UploadEndpoint::class, function ($app) {
            $settings = self::settings();
            return new \Banimark\Http\UploadEndpoint(
                $app->make(\Banimark\Storage\PdoStore::class),
                $app->make(\Banimark\Storage\Attachments::class),
                $app->make(\Banimark\Files\FileStore::class),
                \Banimark\Files\UploadPolicy::fromSettings($settings),
                (string) config('banimark.identity_secret', ''),
                \Banimark\Files\FileStoreFactory::enabled($settings),
            );
        });
        $this->app->bind(\Banimark\Http\FileEndpoint::class, function ($app) {
            return new \Banimark\Http\FileEndpoint(
                $app->make(\Banimark\Storage\Attachments::class),
                $app->make(\Banimark\Files\FileStore::class),
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
                2000, \Banimark\Ai\Behaviour::historyWindow(self::settings()),
                // (the notifier follows; attachments are appended after it)
                // escalation alert: Banimark's OWN mailer (panel SMTP settings),
                // so the host app's mail config is neither required nor touched
                new \Banimark\Notify\CallbackNotifier(function ($sessionId, $label, $reason) use ($app) {
                    $settings = self::settings();
                    // out of hours the owner may want an email regardless of the normal mode
                    $hours = \Banimark\Desk\BusinessHours::fromSettings($settings);
                    $emailAnyway = $hours->policy() === 'queue_email' && !$hours->isOpen();
                    if (($settings['escalation_mode'] ?? 'staff') !== 'email' && !$emailAnyway) { return; }
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
                $app->make(\Banimark\Storage\Attachments::class),
                \Banimark\Ai\Behaviour::dailyCap(self::settings()),
                $app->make(\Banimark\Http\RateLimiter::class),
                \Banimark\Ai\Behaviour::typingGrace(self::settings()),
            );
        });
        $this->app->singleton(\Banimark\Http\RateLimiter::class, function () {
            return new \Banimark\Http\RateLimiter(\Illuminate\Support\Facades\DB::connection()->getPdo());
        });
        $this->app->bind(\Banimark\Http\PollEndpoint::class, function ($app) {
            return new \Banimark\Http\PollEndpoint(
                $app->make(\Banimark\Storage\PdoStore::class),
                (string) config('banimark.identity_secret', ''),
                $app->make(\Banimark\Storage\Attachments::class),
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

    /**
     * For what the check before boot cannot see coming: a loader that IS
     * present but refuses the file - a trial encode past its 36 hours, a
     * damaged download. PHP raises those as fatal errors, which Laravel turns
     * into an exception at shutdown; on a Banimark page, show the fix instead
     * of "Internal Server Error". Anything else is left to the host's handler.
     */
    private function reportCoreFailuresKindly(): void
    {
        $this->callAfterResolving(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler) {
            if (!method_exists($handler, 'renderable')) {
                return;
            }
            $handler->renderable(function (\Throwable $e, $request) {
                if (!class_exists(\Banimark\CoreHealth::class)) {
                    return null;
                }
                $adminPrefix = \Banimark\Laravel\Urls::admin();
                $w = \Banimark\Laravel\Urls::widget();
                if (!$request->is($w, $w.'/*', $adminPrefix, $adminPrefix.'/*')) {
                    return null;   // not ours - the host handles its own errors
                }
                $visitor = $request->expectsJson() || $request->is($w.'/chat*', $w.'/upload', $w.'/file/*', $w.'/widget/*');

                // (1) the core itself could not load - the fix-the-server page
                if (\Banimark\CoreHealth::isCoreFailure($e)) {
                    logger()->error('Banimark core failed to load: '.$e->getMessage());
                    if ($visitor) {
                        return response()->json(\Banimark\CoreHealth::forVisitor(), 503);
                    }
                    return response(\Banimark\CoreHealth::page([
                        'reason' => 'refused',
                        'title' => 'Banimark could not start',
                        'message' => "The ionCube Loader is installed, but it could not run this copy of Banimark's core. The build may have expired, be damaged, or not match this PHP version.",
                        'steps' => [
                            'Install the latest version below, or reinstall it: composer reinstall banimark/banimark',
                            'Make sure the ionCube Loader matches this PHP version: '.\Banimark\CoreHealth::LOADERS_URL,
                            'Restart PHP afterwards - php-fpm, Apache, MAMP, or `php artisan serve`.',
                        ],
                    ], '', class_exists(CoreUnavailable::class) ? CoreUnavailable::updateSection($request) : ''),
                        503, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
                }

                // (2) the core runs, but something on an ADMIN path threw
                // OUTSIDE a controller action (a middleware, a binding) - the
                // controller's own catch (PanelController::callAction) never
                // saw it. Same screen. This callback runs AFTER the host's own
                // render callbacks, so it only wins when the host does not
                // claim the exception. Visitor endpoints handle their own
                // errors (WidgetController answers JSON) - leave them.
                if ($visitor || !$request->is($adminPrefix, $adminPrefix.'/*')
                    || \Banimark\Laravel\AdminErrorPage::isFrameworkFlow($e)) {
                    return null;
                }
                return \Banimark\Laravel\AdminErrorPage::render($request, $e);
            });
        });
    }

    public function boot(): void
    {
        // Can this server run the core at all? Asked BEFORE anything touches it:
        // a trial-encoded core on a PHP without the ionCube Loader does not
        // fail, it prints its payload into the page (seen live). When the
        // answer is no, every Banimark route is still registered - the host may
        // call route('banimark.widget') in its own layout - but answered by
        // CoreUnavailable, which never reaches a controller or the core.
        // Guarded: this runs on EVERY request of the host app. An install with an
        // authoritative classmap that was updated in place cannot see a class
        // that arrived with the update - and "Banimark's health check is
        // missing" must never become "the customer's whole site is down".
        $coreProblem = class_exists(\Banimark\CoreHealth::class)
            ? \Banimark\CoreHealth::problem(dirname(__DIR__))
            : null;
        $this->reportCoreFailuresKindly();
        if ($coreProblem !== null && !$this->app->runningInConsole()) {
            // ahead of routing, the `web` group and any route cache - see CoreUnavailable
            $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
            if (method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(CoreUnavailable::class);
            }
        }

        // widget routes: chat endpoint + configured widget script. Hosts that
        // want custom paths/middleware skip this via config and mount their own.
        if (config('banimark.widget.routes', true)) {
            if ($coreProblem !== null) {
                \Illuminate\Support\Facades\Route::group(['middleware' => [CoreUnavailable::class]], function () {
                    $this->loadRoutesFrom(__DIR__.'/../../routes/widget.php');
                });
            } else {
                $this->loadRoutesFrom(__DIR__.'/../../routes/widget.php');
            }
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
                // a button posted by panel.js gets its flash+redirect as JSON
                // (message at the top of the page + toast); browsers without
                // JS keep the redirect. Inside 'web' (it reads the session),
                // outside the gate (a gate's bounce is an answer too).
                [\Banimark\Laravel\Http\FormAnswer::class],
                (array) config('banimark.admin.extra_middleware', []),
                // NO error-catching middleware here: Laravel's routing pipeline
                // renders an exception through the host's handler before any
                // outer middleware could catch it. PanelController::callAction()
                // is where an admin exception becomes our error screen.
                // the single auth+licence gate for the whole panel - no
                // controller method can bypass or forget it. It lives in the
                // core, so when the core cannot run, the page explaining why
                // takes its place.
                [$coreProblem !== null ? CoreUnavailable::class : \Banimark\Laravel\Http\Middleware\EnsureBanimarkAccess::class],
            )));
            // panel CSS/JS as same-origin files - OUTSIDE the gate: the login page
            // needs them before any session exists, and they carry no secrets.
            // A customer's Content-Security-Policy ('self') allows these where
            // it blocks every inline block and onclick= attribute.
            \Illuminate\Support\Facades\Route::get(
                \Banimark\Laravel\Urls::admin().'/assets/{name}',
                [\Banimark\Laravel\Admin\PanelController::class, 'asset']
            )->where('name', '[a-z]+\\.(css|js|woff2)')->name('banimark.admin.asset')
                ->middleware($coreProblem !== null ? [CoreUnavailable::class] : []);

            // update/reinstall with no login and no panel code - the way out
            // when the admin itself throws. Outside the admin group; the
            // licence key is its gate (RecoverPage).
            $recover = \Banimark\Laravel\Urls::admin().'/recover';
            $recoverMiddleware = array_values(array_unique(array_merge(['web'], (array) config('banimark.admin.extra_middleware', []))));
            \Illuminate\Support\Facades\Route::match(['get', 'post'], $recover, \Banimark\Laravel\RecoverPage::class)
                ->name('banimark.admin.recover')->middleware($recoverMiddleware);
            // the live steps the error screen runs (recover.js), and the script itself
            foreach (['check', 'fetch', 'apply', 'database'] as $step) {
                \Illuminate\Support\Facades\Route::post($recover.'/'.$step, [\Banimark\Laravel\RecoverPage::class, $step])
                    ->name('banimark.admin.recover.'.$step)->middleware($recoverMiddleware);
            }
            \Illuminate\Support\Facades\Route::get($recover.'/recover.js', [\Banimark\Laravel\RecoverPage::class, 'script'])
                ->name('banimark.admin.recover.script');

            \Illuminate\Support\Facades\View::composer('banimark::admin.*', function () {
                \Banimark\Ui\Layout::configure([
                    'assets' => url(\Banimark\Laravel\Urls::admin().'/assets'),
                ]);
            });

            \Illuminate\Support\Facades\Route::group([
                'prefix' => \Banimark\Laravel\Urls::admin(),
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
                \Banimark\Laravel\Console\PruneCommand::class,
            ]);
            $this->publishes([
                __DIR__.'/../../config/banimark.php' => $this->app->configPath('banimark.php'),
            ], 'banimark-config');
        }
    }
}
