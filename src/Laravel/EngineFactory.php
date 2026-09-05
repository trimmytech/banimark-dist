<?php

namespace Banimark\Laravel;

use Banimark\AiManager;
use Banimark\Desk\EscalateTool;
use Banimark\Engine\Engine;
use Banimark\Tools\SqlTool;
use Banimark\Tools\ToolRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Assembles the running Engine from the panel's database rows: the default
 * provider row picks the driver/model/key, enabled rules join into the
 * system instruction, enabled tool rows compile into SqlTools (plus the
 * built-in escalate hatch). Falls back to config('banimark') when the
 * tables are empty, so the package works before the panel is ever opened.
 */
class EngineFactory
{
    public static function make(): Engine
    {
        [$driverName, $providerConfig, $temperature] = self::provider();
        $manager = new AiManager([
            'default' => $driverName,
            'providers' => [$driverName => $providerConfig] + (array) config('banimark.providers', []),
        ]);

        $registry = new ToolRegistry();
        $registry->register(new EscalateTool());
        foreach (self::toolRows() as $row) {
            try {
                $registry->register(SqlTool::fromDefinition($row, function (string $sql, array $bindings) {
                    return array_map(fn ($r) => (array) $r, DB::select($sql, $bindings));
                }));
            } catch (\Throwable $e) {
                Log::warning('banimark: skipped invalid tool "'.($row['name'] ?? '?').'": '.$e->getMessage());
            }
        }

        return new Engine($manager->driver(), $registry, [
            'system' => self::systemInstruction(),
            'temperature' => $temperature,
            'max_tokens' => (int) config('banimark.generation.max_tokens', 2048),
            'max_iterations' => 4,
        ]);
    }

    /** @return array{0:string,1:array,2:float} */
    private static function provider(): array
    {
        try {
            $row = DB::table('banimark_providers')->where('enabled', 1)
                ->orderByDesc('is_default')->orderBy('id')->first();
        } catch (\Throwable $e) {
            $row = null; // tables not migrated yet - config fallback
        }
        if ($row) {
            return [$row->slug, [
                'driver' => $row->driver,
                'api_key' => $row->api_key,
                'model' => $row->model,
                'base_url' => $row->base_url ?: null,
                'name' => $row->slug,
            ], (float) $row->temperature];
        }
        $default = (string) config('banimark.default', 'gemini');
        return [$default, (array) config('banimark.providers.'.$default, []),
            (float) config('banimark.generation.temperature', 0.4)];
    }

    private static function systemInstruction(): string
    {
        $base = "You are a helpful, concise customer support agent. Use the provided tools to look up real data before answering; never invent order or account details. If a tool errors, apologise briefly and offer to escalate.";
        try {
            // folder by folder, in the owner's order - see Storage\Rules
            return (new \Banimark\Storage\Rules(DB::connection()->getPdo()))->systemInstruction($base);
        } catch (\Throwable $e) {
            return $base;
        }
    }

    private static function toolRows(): array
    {
        try {
            return DB::table('banimark_tools')->where('enabled', 1)->get()->map(fn ($r) => [
                'name' => $r->name,
                'description' => $r->description,
                'parameters' => json_decode($r->parameters, true) ?: [],
                'sql' => $r->sql,
                'columns' => json_decode($r->columns, true) ?: [],
                'context' => json_decode((string) $r->context, true) ?: [],
                'max_rows' => (int) $r->max_rows,
            ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
