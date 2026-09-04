<?php

namespace Banimark\Standalone;

/** Tiny key/value access over {prefix}settings for the standalone runtime. */
class Settings
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $st = $this->pdo->prepare("SELECT `value` FROM {$this->prefix}settings WHERE `key` = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $st = $this->pdo->prepare("DELETE FROM {$this->prefix}settings WHERE `key` = ?");
        $st->execute([$key]);
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}settings (`key`, `value`) VALUES (?, ?)");
        $st->execute([$key, $value]);
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT `key`, `value` FROM {$this->prefix}settings") as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }
}
