<?php

namespace Banimark\Update;

use Banimark\CoreHealth;

/**
 * Will THIS server's PHP run the core of a release we have not installed yet?
 *
 * Asked before an update is swapped in, because afterwards is too late: a core
 * the ionCube Loader refuses stops PHP with a fatal error on every Banimark
 * request - the admin that would roll it back included.
 *
 * Two answers, cheapest first:
 *  1. the rule - which encodes each PHP's loader takes (CoreHealth::ACCEPTS),
 *     read against the PHP the release was encoded for;
 *  2. the proof - load the release's core in a SEPARATE PHP process of the
 *     same version, so a refusal kills that process and not this request.
 *     Only a same-version command-line PHP with the loader counts; anything
 *     else (none found, proc_open disabled, a CLI without the loader) is "could
 *     not tell", and the rule stands alone.
 *
 * Only a LOADER refusal blocks an update. Any other oddity in the child process
 * is more likely the command-line PHP's own configuration than the release.
 */
final class CoreProbe
{
    public const OK = 'ok';
    public const REFUSED = 'refused';
    public const UNKNOWN = 'unknown';

    /** @var callable|null fn(string $script): ?string - runs a PHP file, returns its output; null = cannot */
    private $runner;

    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner;
    }

    /**
     * @param string $packageDir the unpacked release (its src/Core.php is judged)
     * @return array{result: string, message: string}
     */
    public function check(string $packageDir, string $version = ''): array
    {
        // the file THIS PHP would load from that release
        $core = CoreHealth::coreFile($packageDir.'/src');
        $head = (string) @file_get_contents($core, false, null, 0, 1024);
        // Only an ionCube file has a loader to refuse it. Its stub opens with
        // "<?php //0..." and names ionCube (licensed) or IONCUBE (trial).
        if ($head === '' || str_contains($head, 'BANIMARK CORE - generated')
            || (!preg_match('~^<\?php //0~', $head) && stripos($head, 'ioncube') === false)) {
            return ['result' => self::OK, 'message' => ''];
        }
        $php = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $named = 'Banimark'.($version !== '' ? ' '.$version : '');

        $for = CoreHealth::encodedFor($head, CoreHealth::buildFor(CoreHealth::build($packageDir.'/src'), basename($core)));
        if ($for !== null && CoreHealth::runsOn($for, $php) === false) {
            return ['result' => self::REFUSED, 'message' => version_compare($for, $php, '>')
                ? "{$named} is built for PHP {$for} and newer, and this server runs PHP {$php}. Ask your host to move this site to PHP {$for} or newer (with the ionCube Loader for it), then update."
                : "{$named} is built for PHP {$for}, which the ionCube Loader for PHP {$php} does not run. Wait for a build that supports PHP {$php}, or ask your vendor."];
        }
        if (!extension_loaded('ionCube Loader')) {
            return ['result' => self::REFUSED, 'message' => "{$named} is protected with ionCube, and this server's PHP ({$php}) has no ionCube Loader. Install it first: ".CoreHealth::LOADERS_URL];
        }

        $out = $this->run($packageDir, $core);
        if ($out === null) {
            return ['result' => self::UNKNOWN, 'message' => ''];
        }
        if (str_contains($out, 'BM-CORE-OK')) {
            return ['result' => self::OK, 'message' => ''];
        }
        if (CoreHealth::isLoaderRefusal($out)) {
            $said = trim((string) preg_replace(['~(/[^\s]+)+~', '~\s+~'], ['', ' '], strip_tags($out)));
            return ['result' => self::REFUSED, 'message' => "This server's PHP {$php} would not run {$named} - the ionCube Loader refused it (\"".mb_substr($said, 0, 200).'"). Nothing was changed.'];
        }
        return ['result' => self::UNKNOWN, 'message' => ''];
    }

    /** Load the release's core in a child PHP; its output, or null when that cannot be done here. */
    private function run(string $packageDir, string $core): ?string
    {
        $src = var_export($packageDir.'/src', true);
        $coreFile = var_export($core, true);
        $script = "<?php\n"
            ."spl_autoload_register(function (\$c) { if (strncmp(\$c, 'Banimark\\\\', 9) === 0 && is_file(\$f = {$src}.'/'.str_replace('\\\\', '/', substr(\$c, 9)).'.php')) require \$f; });\n"
            ."require {$coreFile};\n"
            ."echo class_exists('Banimark\\\\Licensing\\\\Master', false) ? 'BM-CORE-OK' : 'BM-CORE-EMPTY';\n";
        $file = (Paths::workDir(dirname($packageDir)) ?? sys_get_temp_dir()).'/banimark-probe-'.bin2hex(random_bytes(4)).'.php';
        if (@file_put_contents($file, $script) === false) {
            return null;
        }
        try {
            return $this->runner !== null ? ($this->runner)($file) : self::runWithCli($file);
        } finally {
            @unlink($file);
        }
    }

    /**
     * A command-line PHP of THIS minor version, with the loader. PHP_BINARY is
     * php-fpm or the Apache module under a web server, so the CLI is looked for
     * beside it; a different version would answer a different question.
     */
    public static function cliBinary(): ?string
    {
        if (!function_exists('proc_open') || !function_exists('exec')) {
            return null;
        }
        $want = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
        $candidates = [];
        if (PHP_SAPI === 'cli' && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }
        foreach ([PHP_BINDIR.'/php', PHP_BINDIR.'/php'.$want, '/usr/bin/php'.$want, dirname(PHP_BINARY).'/php'] as $c) {
            $candidates[] = $c;
        }
        foreach (array_unique($candidates) as $bin) {
            if (!@is_executable($bin) || str_contains(basename($bin), 'fpm') || str_contains(basename($bin), 'cgi')) {
                continue;
            }
            $out = [];
            @exec(escapeshellarg($bin).' -r '.escapeshellarg('echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION, extension_loaded("ionCube Loader") ? " L" : " -";').' 2>/dev/null', $out);
            if (trim(implode('', $out)) === $want.' L') {
                return $bin;
            }
        }
        return null;
    }

    private static function runWithCli(string $script): ?string
    {
        $bin = self::cliBinary();
        if ($bin === null) {
            return null;
        }
        $proc = @proc_open([$bin, '-d', 'display_errors=1', '-d', 'html_errors=0', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $deadline = microtime(true) + 20;
        while (microtime(true) < $deadline) {
            $out .= (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            if (!proc_get_status($proc)['running']) {
                $out .= (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
                break;
            }
            usleep(50000);
        }
        $running = proc_get_status($proc)['running'];
        if ($running) {
            proc_terminate($proc);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return $running ? null : $out;
    }
}
