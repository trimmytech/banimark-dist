<?php

namespace Banimark;

/**
 * "Can this server run Banimark's core?" - asked BEFORE anything touches it.
 *
 * Readable on purpose, and deliberately free of any core class: it runs in
 * exactly the situation where the core cannot load.
 *
 * Why before, not after: a TRIAL ionCube encode has no loader check in its
 * stub - it is `<?php // ... ?>` followed by the payload - so a PHP without
 * the loader does not fail on it, it PRINTS it. A customer's page filled with
 * base64, then "Target class [...] does not exist" (seen live on the pilot).
 * Nothing can be caught once that has happened, so the answer has to come
 * first.
 *
 * Only safe facts reach the screen: the admin is behind a gate that lives in
 * the core, so this page is public. It names the PHP version and the fix,
 * never a path, a php.ini location or a raw error.
 */
final class CoreHealth
{
    public const LOADERS_URL = 'https://www.ioncube.com/loaders.php';
    public const WIZARD_URL = 'https://www.ioncube.com/lw';

    /** ionCube EULA 12.1: a trial encode stops working this long after it is made. */
    public const TRIAL_LIFETIME = 36 * 3600;

    /** Say "expired" a little EARLY: the page must come before the loader refuses. */
    public const EXPIRY_MARGIN = 600;

    /**
     * Which encodes each PHP's ionCube Loader will run, keyed by the PHP.
     * In the loaders' own words (their fatal errors, and the strings in the
     * 8.4 and 8.5 loader binaries):
     *   8.2      "Only files produced by the PHP 8.2 ionCube Encoder can run on PHP 8.2."
     *   8.3      "... either the PHP 8.2 or 8.3 ionCube Encoders can run on PHP 8.3."
     *   8.4, 8.5 "... either the PHP 8.2 or 8.3 or 8.4 ionCube Encoders can run on PHP 8.4 or 8.5."
     * A PHP not listed here is newer than any loader we have seen: nobody knows
     * yet, so it is tried ONCE and the answer remembered (see recordRefusal()).
     * Add a row when a new loader ships - its own error message says what it takes.
     */
    public const ACCEPTS = [
        '8.1' => ['8.1'],
        '8.2' => ['8.2'],
        '8.3' => ['8.2', '8.3'],
        '8.4' => ['8.2', '8.3', '8.4'],
        '8.5' => ['8.2', '8.3', '8.4'],
    ];

    /**
     * What this build says about itself (src/core-build.json, written by
     * build/publish.php): version, built_at, kind, expires_at, hq_public_key,
     * default_endpoint. Empty for a build made before the file existed.
     */
    public static function build(string $srcDir): array
    {
        $data = json_decode((string) @file_get_contents(rtrim($srcDir, '/').'/core-build.json'), true);
        return is_array($data) ? $data : [];
    }

    /**
     * The PHP an encoded core was encoded FOR ("8.2"), or null when unknown.
     * A licensed encode says so in its header ("// 15.0 82"); a trial encode
     * does not, so the build records it in core-build.json.
     */
    public static function encodedFor(string $head, array $build = []): ?string
    {
        if (preg_match('~^// \d+\.\d+ (\d)(\d)\s*$~m', $head, $m)) {
            return $m[1].'.'.$m[2];
        }
        $v = (string) ($build['encoded_for'] ?? '');
        return preg_match('/^\d+\.\d+$/', $v) ? $v : null;
    }

    /**
     * Will PHP $php's loader run a core encoded for $encodedFor?
     * true / false when known; null for a PHP newer than every loader we know.
     */
    public static function runsOn(string $encodedFor, string $php): ?bool
    {
        if (isset(self::ACCEPTS[$php])) {
            return in_array($encodedFor, self::ACCEPTS[$php], true);
        }
        // no loader has ever run an encode made for a NEWER PHP than itself
        return version_compare($encodedFor, $php, '>') ? false : null;
    }

    /**
     * Where a refusal is remembered: beside the core when PHP may write there,
     * else the temp directory. Keyed by the package path, so two installs on
     * one server never read each other's.
     *
     * @return string[] most preferred first
     */
    private static function refusalFiles(string $srcDir): array
    {
        $srcDir = rtrim($srcDir, '/');
        return [$srcDir.'/.core-refused.json',
            rtrim(sys_get_temp_dir(), '/').'/banimark-core-refused-'.md5((string) (realpath($srcDir) ?: $srcDir)).'.json'];
    }

    /**
     * The core file THIS PHP should load. A build may ship one encode per
     * target PHP (build/encode-targets.php; core-build.json "targets":
     * {"8.2": "Core.php", "8.4": "Core-8.4.php"}). The newest target this
     * PHP's loader is known to run wins; for a PHP newer than every loader we
     * know, the newest target not above it - the likeliest to be accepted, and
     * if it is not, the refusal is remembered. Anything else: src/Core.php.
     */
    public static function coreFile(string $srcDir): string
    {
        return self::coreFileFor($srcDir, PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION);
    }

    /** coreFile() for a given PHP ("8.4") - the same choice, testable for every PHP. */
    public static function coreFileFor(string $srcDir, string $php): string
    {
        $srcDir = rtrim($srcDir, '/');
        $targets = self::build($srcDir)['targets'] ?? [];
        if (!is_array($targets) || $targets === []) {
            return $srcDir.'/Core.php';
        }
        uksort($targets, fn ($a, $b) => version_compare((string) $b, (string) $a));   // newest first
        $maybe = null;
        foreach ($targets as $for => $file) {
            // only a file name of ours, in src/ - this is read from a file on disk
            $file = (string) $file;
            if (!preg_match('/^Core(-\d+\.\d+)?\.php$/', $file) || !is_file($srcDir.'/'.$file)) {
                continue;
            }
            $runs = self::runsOn((string) $for, $php);
            if ($runs === true) {
                return $srcDir.'/'.$file;
            }
            if ($runs === null && $maybe === null) {
                $maybe = $srcDir.'/'.$file;
            }
        }
        return $maybe ?? $srcDir.'/Core.php';
    }

    /**
     * The build info as it applies to ONE of its core files: "encoded_for"
     * describes Core.php, and a trial encode's header does not say its target,
     * so for Core-8.4.php the answer is the target it is listed under.
     */
    public static function buildFor(array $build, string $file): array
    {
        foreach ((array) ($build['targets'] ?? []) as $for => $name) {
            if ($name === $file) {
                return ['encoded_for' => (string) $for] + $build;
            }
        }
        return $file === 'Core.php' ? $build : ['encoded_for' => null] + $build;
    }

    /** What identifies THIS core on THIS PHP: a new core or a new PHP means try again. */
    private static function fingerprint(string $srcDir): array
    {
        $core = self::coreFile($srcDir);
        clearstatcache(true, $core);
        return ['php' => PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, 'size' => (int) @filesize($core), 'mtime' => (int) @filemtime($core)];
    }

    /**
     * The loader refused the core with a fatal error - uncatchable, so this is
     * called from a shutdown function (core_boot.php) and remembered: the next
     * request shows the page that explains it instead of failing the same way.
     * The loader's words are kept (they say which encodes it takes), its file
     * paths are not - this ends up on a public page.
     */
    public static function recordRefusal(string $srcDir, string $loaderMessage): void
    {
        // html_errors is on by default, so the message arrives with <br> and <b>
        $said = html_entity_decode(strip_tags($loaderMessage), ENT_QUOTES, 'UTF-8');
        $said = trim((string) preg_replace('~\s*The (?:encoded )?file .*? (has been|is)~s', ' This file $1', $said));
        $said = (string) preg_replace('~ in Unknown on line \d+~', '', $said);
        $said = trim((string) preg_replace(['~(/[^\s]+)+~', '~\s+~'], ['', ' '], $said));
        $record = json_encode(self::fingerprint($srcDir) + ['said' => mb_substr($said, 0, 400), 'at' => time()]);
        foreach (self::refusalFiles($srcDir) as $file) {
            if (@file_put_contents($file, $record) !== false) {
                return;
            }
        }
    }

    /** The remembered refusal for this core on this PHP, if there is one. */
    public static function refusal(string $srcDir): ?array
    {
        $now = self::fingerprint($srcDir);
        foreach (self::refusalFiles($srcDir) as $file) {
            $r = json_decode((string) @file_get_contents($file), true);
            if (is_array($r) && ($r['php'] ?? '') === $now['php'] && (int) ($r['size'] ?? -1) === $now['size']
                && (int) ($r['mtime'] ?? -1) === $now['mtime']) {
                return $r;
            }
        }
        return null;
    }

    /** Is this fatal error the loader refusing an encoded file? */
    public static function isLoaderRefusal(string $message): bool
    {
        return stripos($message, 'ionCube') !== false
            || stripos($message, 'encoded file') !== false
            || stripos($message, 'has been encoded') !== false
            || stripos($message, 'can run on PHP') !== false;
    }

    /**
     * @param string $srcDir the package's src/ directory
     * @return array{reason: string, title: string, message: string, steps: string[]}|null null = fine
     */
    public static function problem(string $srcDir): ?array
    {
        $core = self::coreFile($srcDir);
        if (!is_file($core)) {
            // the dev tree runs on individual sources and has no Core.php at
            // all - that is healthy, not a missing core
            return is_file(rtrim($srcDir, '/').'/Engine/Engine.php') ? null : self::shape('missing',
                'Banimark is not fully installed',
                'A required file (src/Core.php) is missing from the Banimark package.',
                ['Reinstall the package: composer reinstall banimark/banimark', 'Or re-apply the last update from HQ.']);
        }

        $head = (string) @file_get_contents($core, false, null, 0, 1024);
        if (str_contains($head, 'BANIMARK CORE - generated') || str_contains($head, 'PLACEHOLDER, NOT A BUILD')) {
            return null;   // readable - any PHP runs it
        }

        $php = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        if (!extension_loaded('ionCube Loader')) {
            return self::shape('loader',
                'Banimark needs the ionCube Loader',
                "Banimark's core is protected with ionCube, and this server's PHP ({$php}) does not have the free ionCube Loader installed - or has not been restarted since it was.",
                [
                    "Download the ionCube Loader for PHP {$php} for your server from ".self::LOADERS_URL,
                    "Add it to php.ini as the FIRST zend_extension line: zend_extension=/path/to/ioncube_loader_<os>_{$php}.so",
                    'Restart PHP so it reads php.ini again - php-fpm, Apache, MAMP, or `php artisan serve`, whichever runs this site.',
                    'Not sure which file or php.ini? The ionCube Loader Wizard works it out for you: '.self::WIZARD_URL,
                    'On shared hosting, ask your host to enable the ionCube Loader - most already offer it.',
                ]);
        }

        // A TRIAL build that has run out. The loader would refuse it outright
        // (and a refused file is a fatal, not something to catch), so decide
        // from what the build says about itself - or, for a trial build made
        // before core-build.json existed, from when the file was written.
        $trial = stripos($head, 'ENCODER') !== false && stripos($head, 'EVALUATION') !== false;
        if ($trial) {
            $build = self::build($srcDir);
            // stat results are cached per process; a long-running worker must
            // not keep judging a build by the file it replaced
            clearstatcache(true, $core);
            $expires = (int) ($build['expires_at'] ?? 0) ?: ((int) @filemtime($core) + self::TRIAL_LIFETIME);
            if (time() >= $expires - self::EXPIRY_MARGIN) {
                $version = (string) ($build['version'] ?? '');
                return self::shape('expired',
                    'This Banimark test build has expired',
                    'Banimark '.($version !== '' ? $version.' ' : '').'is a TEST build, made with a trial encoder. Trial builds stop working 36 hours after they are made, and this one ran out on '
                        .date('D j M, H:i', $expires).'.',
                    [
                        'Install the latest version below - it takes a few seconds and keeps all your data.',
                        'If nothing newer is listed yet, ask your vendor for a new build, then check again.',
                    ]) + ['version' => $version, 'expired_at' => $expires, 'updatable' => true];
            }
        }

        // Which PHPs this core runs on is the loader's rule, not ours - and a
        // file the loader refuses is a FATAL, not an exception. So when the
        // table above knows the answer, say so before PHP gets the chance.
        $build = self::build($srcDir);
        $for = self::encodedFor($head, self::buildFor($build, basename($core)));
        if ($for !== null && self::runsOn($for, $php) === false) {
            return self::phpMismatch($for, $php, (string) ($build['version'] ?? ''));
        }

        // A PHP newer than every loader we know was tried once and refused:
        // remembered, so every later request gets this page, not the fatal.
        if (($refused = self::refusal($srcDir)) !== null) {
            return self::phpMismatch($for ?? '', $php, (string) ($build['version'] ?? ''), (string) ($refused['said'] ?? ''));
        }
        return null;
    }

    /**
     * Did this exception come from the core failing to load, rather than from
     * a bug? Used AFTER the fact, for what the check above cannot see coming -
     * a loader that is present but refuses the file (a trial that has expired,
     * a damaged download).
     */
    public static function isCoreFailure(\Throwable $e): bool
    {
        $message = $e->getMessage();
        if (self::isLoaderRefusal($message)) {
            return true;
        }
        // "Target class [Banimark\...] does not exist" while the core is not in
        // memory: the core did not load, whatever the reason
        return str_contains($message, 'Banimark\\')
            && str_contains($message, 'does not exist')
            && !class_exists('Banimark\\Engine\\Engine', false);
    }

    /** The same problem, for a visitor's widget rather than an owner. */
    public static function forVisitor(): array
    {
        return ['ok' => false, 'error' => 'Support chat is temporarily unavailable. Please try again a little later.'];
    }

    /**
     * A whole page, owing nothing to the core or a template engine. No script
     * (customer CSPs block inline JS); one <style> block, which the panel's CSP
     * requirement already allows.
     *
     * @param string $extraHtml trusted markup built by the caller (the update section)
     */
    public static function page(array $problem, string $supportEmail = '', string $extraHtml = ''): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $steps = '';
        foreach ($problem['steps'] as $step) {
            // the only markup in a step is the URLs, made clickable here
            $safe = $e($step);
            $safe = (string) preg_replace('~(https://[^\s<)]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $safe);
            $safe = (string) preg_replace('~`([^`]+)`~', '<code>$1</code>', $safe);
            $steps .= '<li>'.$safe.'</li>';
        }
        $support = $supportEmail !== ''
            ? '<p class="muted">Still stuck? Email <a href="mailto:'.$e($supportEmail).'">'.$e($supportEmail).'</a>.</p>'
            : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex">'
            .'<title>Banimark - '.$e($problem['title']).'</title>'
            .'<style>'
            .':root{--bg:#f2f5f4;--card:#fff;--text:#0b1b1e;--muted:#768789;--brand:#0e7c73;--ink:#fff;--border:#dde6e4}'
            .'@media (prefers-color-scheme:dark){:root{--bg:#070d0e;--card:#0f181a;--text:#eaf2f1;--muted:#7e9391;--brand:#2cc7b4;--ink:#03231f;--border:#1f2e30}}'
            .'*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif}'
            .'main{max-width:640px;margin:8vh auto;padding:0 16px}'
            .'.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:28px}'
            .'.tag{display:inline-block;font-size:12px;font-weight:600;letter-spacing:.04em;color:var(--brand);text-transform:uppercase;margin-bottom:6px}'
            .'h1{font-size:21px;margin:0 0 10px}ol{padding-left:20px}li{margin:8px 0}'
            .'a{color:var(--brand)}code{background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:1px 5px;font-size:13px}'
            .'.muted{color:var(--muted);font-size:13.5px}.ok{border-left:3px solid var(--brand);padding-left:12px;margin-top:18px}'
            .self::UPDATE_CSS
            .'</style></head><body><main><div class="card">'
            .'<div class="tag">Banimark</div>'
            .'<h1>'.$e($problem['title']).'</h1>'
            .'<p>'.$e($problem['message']).'</p>'
            .($steps !== '' ? '<p><b>How to fix it</b></p><ol>'.$steps.'</ol>' : '')
            .$extraHtml
            .'<p class="muted ok">Nothing is lost: your conversations, settings and staff accounts are untouched, and the rest of your site keeps working. Reload this page once it is fixed.</p>'
            .$support
            .'</div></main></body></html>';
    }

    /** The update box (CoreUnavailable::updateSection), on both pages. */
    private const UPDATE_CSS = '.upd{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:16px;margin:16px 0}'
        .'.upd h2{font-size:16px;margin:0 0 6px}.upd input{width:100%;padding:9px 11px;border:1px solid var(--border);border-radius:8px;background:var(--card);color:var(--text);font:inherit;margin:6px 0 10px}'
        .'.upd button,.upd .btn{display:inline-block;background:var(--brand);color:var(--ink,#fff);border:1px solid var(--brand);border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;margin:4px 6px 0 0}'
        .'.upd button.ghost,.upd .btn.ghost{background:transparent;color:var(--brand);border-color:var(--border)}'
        .'.pill{display:inline-block;font-size:11px;font-weight:700;padding:2px 7px;border-radius:99px;background:#fde7c8;color:#8a4b00;margin-left:6px}'
        .'.bad{color:#b42318}.good{color:#067647}'
        // the live steps (recover.js) - the changelog page's look, panel.css
        .'.bm-steps{margin:14px 0 0;display:grid;gap:2px}'
        .'.bm-step{display:grid;grid-template-columns:22px minmax(0,1fr);gap:10px;align-items:start;padding:8px 0;font-size:13.5px;color:var(--muted)}'
        .'.bm-step .dot{width:16px;height:16px;margin-top:2px;border-radius:50%;border:2px solid var(--border);display:grid;place-items:center}'
        .'.bm-step.on,.bm-step.done{color:var(--text)}'
        .'.bm-step.on .dot{border-color:var(--brand);border-right-color:transparent;animation:bmspin .7s linear infinite}'
        .'.bm-step.done .dot{border-color:#067647;background:#067647}'
        .'.bm-step.done .dot::after{content:"";width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg) translate(-1px,-1px)}'
        .'.bm-step.bad{color:var(--text)}.bm-step.bad .dot{border-color:#b42318;background:#b42318}'
        .'.bm-step.bad .dot::after{content:"!";color:#fff;font-size:10px;font-weight:800;line-height:1}'
        .'.bm-step .why{display:block;font-size:12.5px;color:var(--muted);margin-top:2px}.bm-step.bad .why{color:#b42318}'
        .'@keyframes bmspin{to{transform:rotate(360deg)}}'
        .'.bm-install{margin-top:14px}[hidden]{display:none!important}';

    /**
     * The "email support" link for an admin error: what the owner's mail app
     * opens with - the details we would ask for anyway. '' without an address.
     *
     * @param array{support_email?: string, version?: string, site?: string, url?: string} $ctx
     */
    public static function errorMailto(\Throwable $ex, array $ctx = []): string
    {
        $support = trim((string) ($ctx['support_email'] ?? ''));
        if ($support === '') {
            return '';
        }
        $version = trim((string) ($ctx['version'] ?? ''));
        $site = trim((string) ($ctx['site'] ?? ''));
        $url = trim((string) ($ctx['url'] ?? ''));
        $class = get_class($ex);
        // a trimmed trace: enough to act on, short enough for a mailto body
        $trace = implode("\n", array_slice(explode("\n", $ex->getTraceAsString()), 0, 12));
        $body = "A Banimark admin page hit an error. Details below - please keep them; they say exactly what to fix.\n\n"
            ."Site:    ".($site !== '' ? $site : '(unknown)')."\n"
            ."Version: ".($version !== '' ? $version : '(unknown)')."\n"
            ."PHP:     ".PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION."\n"
            ."URL:     ".($url !== '' ? $url : '(unknown)')."\n"
            ."When:    ".date('c')."\n\n"
            ."{$class}: ".$ex->getMessage()."\n"
            ."at ".$ex->getFile().':'.$ex->getLine()."\n\n"
            ."{$trace}";
        $subject = 'Banimark error'.($version !== '' ? ' '.$version : '').' - '.$class;
        return 'mailto:'.$support.'?subject='.rawurlencode($subject).'&body='.rawurlencode($body);
    }

    /**
     * A friendly page for an unhandled ADMIN exception - NOT a core-load
     * failure (that is problem()/page()). The core is running fine; a page just
     * threw. It shows the owner what broke, a button to check for an update (a
     * newer build may already fix it), and a button that opens their mail app
     * with the details addressed to whoever supplies their Banimark.
     *
     * Admin/staff-facing only - the widget and visitor API answer JSON and
     * never reach here. Readable and core-independent so it renders whatever
     * state the install is in. It shows the message, where, and a trimmed
     * trace - never the request body, POST data or environment.
     *
     * @param array{support_email?: string, update_url?: string, update_html?: string, version?: string, site?: string, url?: string} $ctx
     */
    public static function exceptionPage(\Throwable $ex, array $ctx = []): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $php = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
        $version = trim((string) ($ctx['version'] ?? ''));
        $site = trim((string) ($ctx['site'] ?? ''));
        $url = trim((string) ($ctx['url'] ?? ''));
        $support = trim((string) ($ctx['support_email'] ?? ''));
        $updateUrl = trim((string) ($ctx['update_url'] ?? ''));
        // the updater itself, IN the page (trusted markup from the caller).
        // When the whole admin throws, a link to the changelog page is a link
        // to another error - so when this is given, the link is not.
        $updateHtml = (string) ($ctx['update_html'] ?? '');

        $class = get_class($ex);
        $where = $ex->getFile().':'.$ex->getLine();
        $trace = implode("\n", array_slice(explode("\n", $ex->getTraceAsString()), 0, 12));
        $mailto = self::errorMailto($ex, $ctx);

        // with the update box on the page, the box owns both outcomes - an
        // update to install, or (none to be had) the email to support
        $buttons = '';
        if ($updateHtml === '' && $updateUrl !== '') {
            $buttons .= '<a class="btn" href="'.$e($updateUrl).'">Check for an update</a> ';
        }
        if ($updateHtml !== '') {
            // the box shows the right one once it knows whether an update exists
        } elseif ($mailto !== '') {
            $buttons .= '<a class="btn ghost" href="'.$e($mailto).'">Email the details to support</a>';
        } elseif ($support === '') {
            $buttons .= '<span class="muted">Send the details below to whoever supplies your Banimark.</span>';
        }

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex">'
            .'<title>Banimark - something went wrong</title>'
            .'<style>'
            .':root{--bg:#f2f5f4;--card:#fff;--text:#0b1b1e;--muted:#768789;--brand:#0e7c73;--ink:#fff;--border:#dde6e4}'
            .'@media (prefers-color-scheme:dark){:root{--bg:#070d0e;--card:#0f181a;--text:#eaf2f1;--muted:#7e9391;--brand:#2cc7b4;--ink:#03231f;--border:#1f2e30}}'
            .'*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,sans-serif}'
            .'main{max-width:680px;margin:8vh auto;padding:0 16px}'
            .'.card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:28px}'
            .'.tag{display:inline-block;font-size:12px;font-weight:600;letter-spacing:.04em;color:var(--brand);text-transform:uppercase;margin-bottom:6px}'
            .'h1{font-size:21px;margin:0 0 10px}a{color:var(--brand)}'
            .'.muted{color:var(--muted);font-size:13.5px}'
            .'.err{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:14px;margin:14px 0;font:13px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;overflow-x:auto;white-space:pre-wrap;word-break:break-word}'
            .'.err b{color:#b42318}'
            .'details{margin-top:16px}summary{cursor:pointer;color:var(--muted);font-size:13.5px}'
            .'.btn{display:inline-block;background:var(--brand);color:var(--ink,#fff);border:0;border-radius:8px;padding:9px 16px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;margin:4px 6px 0 0}'
            .'.btn.ghost{background:transparent;color:var(--brand);border:1px solid var(--border)}'
            .'.ok{border-left:3px solid var(--brand);padding-left:12px;margin-top:18px}'
            .self::UPDATE_CSS
            .'</style></head><body><main><div class="card">'
            .'<div class="tag">Banimark</div>'
            .'<h1>Something went wrong on this page</h1>'
            .'<p class="muted">Your chat widget and the rest of your site are unaffected - this is only the admin page you just opened.</p>'
            .'<div class="err"><b>'.$e($class).'</b>: '.$e($ex->getMessage()).'</div>'
            // the way out comes before the trace: a 12-frame trace pushed the
            // updater below the fold (seen in a screenshot of the pilot)
            .$updateHtml
            .'<div>'.$buttons.'</div>'
            // <details> opens with no script (customer CSPs block inline JS)
            .'<details><summary>Technical details</summary><div class="err"><span class="muted">at '.$e($where).'</span>'
            .'<br><br>'.$e($trace).'</div></details>'
            .($updateHtml !== '' ? '' : '<p class="muted ok">A newer version often fixes this - check for an update first. If it persists, email us the details above and we will look.</p>')
            .'</div></main></body></html>';
    }

    /** The core and this PHP do not go together - in whichever direction. */
    private static function phpMismatch(string $for, string $php, string $version, string $loaderSaid = ''): array
    {
        $named = 'Banimark'.($version !== '' ? ' '.$version : '');
        if ($for !== '' && version_compare($for, $php, '>')) {
            // a build for a NEWER PHP than this server runs
            return self::shape('php_version',
                'This Banimark version needs a newer PHP',
                "{$named} is built for PHP {$for} and newer, and this server runs PHP {$php}.",
                [
                    "Ask your host to move this site to PHP {$for} or newer - with the ionCube Loader for that PHP (".self::LOADERS_URL.').',
                    'Or put the version you had back: the updater keeps it beside this one (a folder named banimark.bak-...), or: composer require "banimark/banimark:<the version you had>"',
                ]) + ['updatable' => false, 'encoded_for' => $for];
        }
        // this server's PHP is NEWER than the build - usually a PHP upgrade on the server
        return self::shape('php_version',
            'This Banimark build does not run on PHP '.$php.' yet',
            ($for !== '' ? "{$named} was built for PHP {$for}, and the ionCube Loader for PHP {$php} will not run it."
                : "The ionCube Loader for PHP {$php} refused to run {$named}.")
                .($loaderSaid !== '' ? ' The loader says: "'.$loaderSaid.'"' : ''),
            [
                'Install the latest version below - a newer build may already support PHP '.$php.'.',
                "If nothing newer is listed, switch this site back to the PHP it ran on before (your hosting panel's PHP version setting) and ask your vendor for a build for PHP {$php}.",
            ]) + ['updatable' => true, 'encoded_for' => $for];
    }

    private static function shape(string $reason, string $title, string $message, array $steps): array
    {
        return ['reason' => $reason, 'title' => $title, 'message' => $message, 'steps' => $steps];
    }
}
