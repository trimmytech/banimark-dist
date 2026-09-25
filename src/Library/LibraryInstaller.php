<?php

namespace Banimark\Library;

use Banimark\Licensing\Entitlements;
use Banimark\Storage\Rules;

/**
 * Puts a rule pack or a tool template into the desk. Framework-free (a PDO),
 * so both runtimes install exactly the same way.
 */
final class LibraryInstaller
{
    public function __construct(private \PDO $pdo, private string $prefix = 'banimark_')
    {
    }

    /* ---------------- tools ---------------- */

    /** @return array<string, string> template slug => tool name, for the templates already added */
    public function installedTemplates(): array
    {
        $out = [];
        try {
            foreach ($this->pdo->query("SELECT name, template FROM {$this->prefix}tools WHERE template <> ''")->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['template']] = (string) $r['name'];
            }
        } catch (\Throwable $e) { /* a schema that has not caught up: nothing installed yet */ }
        return $out;
    }

    /** @return array{0:int, 1:int} [templates, all tools] */
    public function toolCounts(): array
    {
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$this->prefix}tools")->fetchColumn();
        return [count($this->installedTemplates()), $total];
    }

    /** @return array{ok:bool, message:string} */
    public function installTool(string $slug, array $verdict): array
    {
        $t = ToolTemplates::get($slug);
        $d = ToolTemplates::definition($slug);
        if ($t === null || $d === null) {
            return ['ok' => false, 'message' => 'That template does not exist.'];
        }
        if (isset($this->installedTemplates()[$slug])) {
            return ['ok' => false, 'message' => '"'.$t['title'].'" is already one of your tools.'];
        }
        $st = $this->pdo->prepare("SELECT 1 FROM {$this->prefix}tools WHERE name = ?");
        $st->execute([$d['name']]);
        if ($st->fetchColumn()) {
            return ['ok' => false, 'message' => 'You already have a tool called "'.$d['name'].'". Rename or remove it first.'];
        }
        [$templates, $total] = $this->toolCounts();
        if (($refuse = Entitlements::refuseTemplate($verdict, $templates, $total)) !== null) {
            return ['ok' => false, 'message' => $refuse];
        }
        // the same gate as a hand-built tool: a template that would not compile never lands
        \Banimark\Tools\ToolFactory::make($d, fn () => []);
        $sql = \Banimark\Storage\Dialect::quote(\Banimark\Storage\Dialect::of($this->pdo), 'sql');
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare("INSERT INTO {$this->prefix}tools (name, description, parameters, {$sql}, columns, context, max_rows, kind, config, template, enabled, created_at, updated_at)
            VALUES (?, ?, ?, '', '[]', ?, ?, 'http', ?, ?, 1, ?, ?)")
            ->execute([$d['name'], $d['description'], json_encode($d['parameters'] ?: new \stdClass()), json_encode($d['context']),
                (int) $d['max_rows'], json_encode($d['config']), $slug, $now, $now]);
        return ['ok' => true, 'message' => '"'.$t['title'].'" is now a tool ('.$d['name'].'). Open it to see how it is built, or press Try it.'];
    }

    /* ---------------- rules ---------------- */

    /** @return array<string, true> "pack/key" of every library rule already in the desk */
    public function installedRules(): array
    {
        $out = [];
        try {
            foreach ($this->pdo->query("SELECT source FROM {$this->prefix}rules WHERE source <> ''")->fetchAll(\PDO::FETCH_COLUMN) as $s) {
                $out[(string) $s] = true;
            }
        } catch (\Throwable $e) {}
        return $out;
    }

    /**
     * @param string[] $keys the rules to add (empty = the whole pack)
     * @return array{ok:bool, added:int, skipped:int, message:string}
     */
    public function installRules(string $packSlug, array $keys = []): array
    {
        $pack = RulePacks::pack($packSlug);
        if ($pack === null) {
            return ['ok' => false, 'added' => 0, 'skipped' => 0, 'message' => 'That rule pack does not exist.'];
        }
        $want = $keys === [] ? array_keys($pack['rules']) : array_values(array_intersect($keys, array_keys($pack['rules'])));
        if ($want === []) {
            return ['ok' => false, 'added' => 0, 'skipped' => 0, 'message' => 'Tick at least one rule to add.'];
        }
        $rules = new Rules($this->pdo, $this->prefix);
        $have = $this->installedRules();
        $folders = [];
        foreach ($rules->tree() as $f) {
            $folders[mb_strtolower((string) $f['title'])] = (int) $f['id'];
        }
        $folderFor = function (string $title, string $description) use ($rules, &$folders): int {
            $k = mb_strtolower($title);
            if (!isset($folders[$k])) {
                $folders[$k] = (int) $rules->createFolder($title, $description);
            }
            return $folders[$k];
        };
        $added = 0;
        $skipped = 0;
        foreach ($want as $key) {
            $source = $packSlug.'/'.$key;
            if (isset($have[$source])) {
                $skipped++;
                continue;
            }
            [$title, $content] = $pack['rules'][$key];
            $folderTitle = $pack['folder'] ?? ($pack['rules'][$key][2] ?? 'Custom instructions');
            $desc = $pack['folder'] !== null ? $pack['blurb'] : '';
            $id = $rules->addRule($folderFor($folderTitle, $desc), $title, $content);
            $this->pdo->prepare("UPDATE {$this->prefix}rules SET source = ? WHERE id = ?")->execute([$source, (int) $id]);
            $added++;
        }
        $message = $added === 0
            ? 'Those rules are already in your desk.'
            : 'Added '.$added.' rule'.($added === 1 ? '' : 's').' from "'.$pack['title'].'"'.($skipped ? ' ('.$skipped.' already there)' : '').'. They apply from the next message - edit any of them below.';
        return ['ok' => true, 'added' => $added, 'skipped' => $skipped, 'message' => $message];
    }
}
