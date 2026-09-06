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

    /** extension => mime actually stored (the browser's Content-Type is not trusted) */
    public const TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
        'webp' => 'image/webp', 'heic' => 'image/heic', 'svg' => 'image/svg+xml',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'log' => 'text/plain',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'wav' => 'audio/wav',
    ];

    /** Never stored, whatever the settings say - these are executables on someone's server. */
    public const NEVER = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'htaccess', 'htpasswd',
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb', 'jsp', 'asp', 'aspx', 'dll', 'so'];

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
        // an SVG is a script container; only allow it when it carries no script
        if ($ext === 'svg' && $bytes !== '' && preg_match('/<script|javascript:|onload\s*=/i', $bytes)) {
            return ['ok' => false, 'error' => 'That SVG contains scripting, so it was refused.'];
        }
        return ['ok' => true, 'ext' => $ext, 'mime' => self::TYPES[$ext] ?? 'application/octet-stream', 'name' => self::safeName($filename)];
    }

    /** The name shown in the chat - the original, stripped of anything dangerous. */
    public static function safeName(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1f\x7f]/', '', $name) ?? '';
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        return mb_substr($name === '' ? 'file' : $name, 0, 120);
    }

    /** Where the bytes go: random, dated, and never derived from the visitor's name. */
    public static function key(string $ext): string
    {
        return 'banimark/'.gmdate('Y/m').'/'.bin2hex(random_bytes(16)).'.'.preg_replace('/[^a-z0-9]/', '', strtolower($ext));
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
    }
}
