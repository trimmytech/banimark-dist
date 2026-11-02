<?php

namespace Banimark\Storage;

/**
 * The uploaded CSV behind a spreadsheet tool - one per tool, replaced on the
 * next upload, deleted with the tool. A sheet shared by link is not stored:
 * it is fetched live (SheetTool). Framework-free; both runtimes use it.
 */
final class Sheets
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    public function read(string $name): ?string
    {
        try {
            $st = $this->pdo->prepare("SELECT csv FROM {$this->prefix}sheets WHERE name = ?");
            $st->execute([$name]);
            $csv = $st->fetchColumn();
        } catch (\Throwable $e) {
            return null; // schema not caught up yet
        }
        return $csv === false ? null : (string) $csv;
    }

    /** @return array{rows: int, updated_at: string}|null */
    public function info(string $name): ?array
    {
        try {
            $st = $this->pdo->prepare("SELECT row_count, updated_at FROM {$this->prefix}sheets WHERE name = ?");
            $st->execute([$name]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        return $row ? ['rows' => (int) $row['row_count'], 'updated_at' => (string) $row['updated_at']] : null;
    }

    public function put(string $name, string $csv, int $rows): void
    {
        $this->delete($name);
        $this->pdo->prepare("INSERT INTO {$this->prefix}sheets (name, csv, row_count, updated_at) VALUES (?, ?, ?, ?)")
            ->execute([$name, $csv, $rows, date('Y-m-d H:i:s')]);
    }

    /** A renamed tool keeps its sheet. */
    public function rename(string $from, string $to): void
    {
        if ($from !== $to) {
            $this->delete($to);
            $this->pdo->prepare("UPDATE {$this->prefix}sheets SET name = ? WHERE name = ?")->execute([$to, $from]);
        }
    }

    public function delete(string $name): void
    {
        try {
            $this->pdo->prepare("DELETE FROM {$this->prefix}sheets WHERE name = ?")->execute([$name]);
        } catch (\Throwable $e) {
        }
    }
}
