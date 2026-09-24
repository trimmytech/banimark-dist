<?php

namespace Banimark\Standalone;

/** Tiny key/value access over {prefix}settings for the standalone runtime. */
class Settings
{
    /** `key` and `value` are reserved words; how they are quoted depends on the engine. */
    private string $k;
    private string $v;

    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
        $driver = \Banimark\Storage\Dialect::of($pdo);
        $this->k = \Banimark\Storage\Dialect::quote($driver, 'key');
        $this->v = \Banimark\Storage\Dialect::quote($driver, 'value');
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $st = $this->pdo->prepare("SELECT {$this->v} FROM {$this->prefix}settings WHERE {$this->k} = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false ? $default : (string) $v;
    }

    public function set(string $key, string $value): void
    {
        $st = $this->pdo->prepare("DELETE FROM {$this->prefix}settings WHERE {$this->k} = ?");
        $st->execute([$key]);
        $st = $this->pdo->prepare("INSERT INTO {$this->prefix}settings ({$this->k}, {$this->v}) VALUES (?, ?)");
        $st->execute([$key, $value]);
    }

    public function all(): array
    {
        $out = [];
        foreach ($this->pdo->query("SELECT {$this->k}, {$this->v} FROM {$this->prefix}settings") as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }
}
