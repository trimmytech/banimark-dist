<?php

namespace Banimark\Files;

/**
 * What may be uploaded, and under what name. Deliberately conservative: an
 * upload endpoint that a stranger can reach is the most exposed thing a
 * support desk has, so the type list is an ALLOW-list and the stored name is
 * never the one the browser sent.
 */
final class UploadPolicy
{
    public const DEFAULT_MAX_MB = 10;

    /**
     * extension => mime actually stored (the browser's Content-Type is not trusted).
     * No SVG, HTML or XML: they are script containers, and a support chat does
     * not need them. The bytes of every accepted file are sniffed and must be
     * what the extension claims (see sniff()).
     */
    public const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'heic' => 'image/heic',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'log' => 'text/plain',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
    ];

    /**
     * Never stored, whatever the settings say: executables on someone's server,
     * and anything a browser would run as a page or a script.
     */
    public const NEVER = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar', 'htaccess', 'htpasswd',
        'exe', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx', 'dll', 'so', 'msi', 'scr',
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xsl', 'xslt', 'js', 'mjs', 'jsx', 'vbs', 'ps1', 'jar', 'swf', 'apk', 'dmg', 'pkg'];

    /** What the first bytes of a real file of each kind look like. */
    private const MAGIC = [
        'png' => ["\x89PNG\r\n\x1a\n"],
        'jpg' => ["\xFF\xD8\xFF"], 'jpeg' => ["\xFF\xD8\xFF"],
        'gif' => ['GIF87a', 'GIF89a'],
        'webp' => ['RIFF'],           // + 'WEBP' at offset 8, checked below
        'heic' => ['ftyp@4'],         // ISO base media: 'ftyp' at offset 4
        'pdf' => ['%PDF-'],
        'doc' => ["\xD0\xCF\x11\xE0"], 'xls' => ["\xD0\xCF\x11\xE0"], 'ppt' => ["\xD0\xCF\x11\xE0"],
        'docx' => ["PK\x03\x04"], 'xlsx' => ["PK\x03\x04"], 'pptx' => ["PK\x03\x04"], 'zip' => ["PK\x03\x04", "PK\x05\x06"],
        'mp4' => ['ftyp@4'], 'mov' => ['ftyp@4', 'moov@4', 'mdat@4', 'wide@4'], 'm4a' => ['ftyp@4'],
        'mp3' => ['ID3', "\xFF\xFB", "\xFF\xF3", "\xFF\xF2"],
        'wav' => ['RIFF'],            // + 'WAVE' at offset 8
    ];

    public function __construct(
        private int $maxBytes = self::DEFAULT_MAX_MB * 1024 * 1024,
        /** @var string[] allowed extensions; empty = the default list */
        private array $allowed = [],
    ) {
    }

    /** @param array<string,string> $settings the settings table */
    public static function fromSettings(array $settings): self
    {
        $mb = (int) ($settings['files_max_mb'] ?? self::DEFAULT_MAX_MB);
        $list = array_values(array_filter(array_map(
            fn ($e) => strtolower(ltrim(trim($e), '.')),
            explode(',', (string) ($settings['files_types'] ?? ''))
        )));
        return new self(max(1, min(100, $mb)) * 1024 * 1024, $list);
    }

    /** @return string[] */
    public function allowedExtensions(): array
    {
        $list = $this->allowed !== [] ? $this->allowed : array_keys(self::TYPES);
        return array_values(array_diff($list, self::NEVER));
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * @return array{ok: bool, error?: string, ext?: string, mime?: string, name?: string}
     */
    public function check(string $filename, int $size, string $bytes = ''): array
    {
        if ($size <= 0) {
            return ['ok' => false, 'error' => 'That file is empty.'];
        }
        if ($size > $this->maxBytes) {
            return ['ok' => false, 'error' => 'That file is larger than '.round($this->maxBytes / 1048576, 1).' MB.'];
        }
        $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext === '' || in_array($ext, self::NEVER, true) || !in_array($ext, $this->allowedExtensions(), true)) {
            return ['ok' => false, 'error' => 'That kind of file is not accepted here.'];
        }
        // "shell.php.png": we never store under that name, but a misconfigured
        // host somewhere might one day serve a download by it - refuse the trick outright
        foreach (array_slice(explode('.', strtolower(basename($filename))), 1, -1) as $inner) {
            if (in_array($inner, self::NEVER, true)) {
                return ['ok' => false, 'error' => 'That kind of file is not accepted here.'];
            }
        }
        if ($bytes !== '' && !self::sniff($ext, $bytes)) {
            // a .png that is really HTML, a "pdf" that is a PHP script... the name lied
            return ['ok' => false, 'error' => 'That file does not look like a '.strtoupper($ext).' file, so it was refused.'];
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => self::TYPES[$ext] ?? 'application/octet-stream', 'name' => self::safeName($filename)];
    }

    /**
     * Do the bytes match the extension? Binary kinds must open with their
     * signature; text kinds must be text (no NUL bytes) and carry no markup a
     * server or browser might run. Every kind refuses a PHP open tag anywhere
     * in the first 64 KB - the classic polyglot defence for misconfigured hosts.
     */
    public static function sniff(string $ext, string $bytes): bool
    {
        $head = substr($bytes, 0, 65536);
        if (preg_match('/<\?(php|=)/i', $head)) {
            return false;
        }
        if (in_array($ext, ['txt', 'csv', 'log'], true)) {
            return strpos($head, "\0") === false && !preg_match('/<(script|html|body|iframe|svg|object|embed)\b/i', $head);
        }
        $sigs = self::MAGIC[$ext] ?? null;
        if ($sigs === null) {
            return false; // an extension we cannot verify is an extension we do not accept
        }
        foreach ($sigs as $sig) {
            $at = 0;
            if (str_contains($sig, '@')) {
                [$sig, $at] = explode('@', $sig);
                $at = (int) $at;
            }
            if (substr($bytes, $at, strlen($sig)) === $sig) {
                if ($ext === 'webp') {
                    return substr($bytes, 8, 4) === 'WEBP';
                }
                if ($ext === 'wav') {
                    return substr($bytes, 8, 4) === 'WAVE';
                }
                return true;
            }
        }
        return false;
    }

    /** The name shown in the chat - the original, stripped of anything dangerous. */
    public static function safeName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        return mb_substr($name === '' ? 'file' : $name, 0, 120);
    }

    /**
     * Where the bytes go: random, dated, never derived from the visitor's name,
     * and ALWAYS ".bin" - so if the folder is ever exposed by a web server, it
     * hands out opaque downloads, never something a browser would render.
     * The real type travels in the attachments row, not the file name.
     */
    public static function key(string $ext): string
    {
        return 'banimark/'.gmdate('Y/m').'/'.bin2hex(random_bytes(16)).'.bin';
    }

    /** Served MIME: only a type from this list, never whatever a row might say. */
    public static function servedMime(string $mime): string
    {
        return in_array($mime, self::TYPES, true) ? $mime : 'application/octet-stream';
    }

    /**
     * A Content-Disposition header that cannot be broken out of: ASCII-only
     * quoted name (no quotes, no CR/LF), plus the RFC 5987 UTF-8 form so
     * browsers still show the real name.
     */
    public static function disposition(string $name, bool $download): string
    {
        $name = self::safeName($name);
        $ascii = preg_replace('/[^\x20-\x7e]/', '_', $name) ?? 'file';
        $ascii = str_replace(['"', '\\', ';'], '_', $ascii);
        return ($download ? 'attachment' : 'inline').'; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name);
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
    }
}
