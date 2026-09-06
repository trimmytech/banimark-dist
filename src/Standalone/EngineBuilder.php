<?php

namespace Banimark\Standalone;

use Banimark\AiManager;
use Banimark\Desk\EscalateTool;
use Banimark\Engine\Engine;
use Banimark\Tools\SqlTool;
use Banimark\Tools\ToolRegistry;

/** The standalone twin of Laravel\EngineFactory: DB rows -> running Engine. */
class EngineBuilder
{
    public static function make(\PDO $pdo, string $prefix = 'banimark_'): Engine
    {
        $provider = $pdo->query("SELECT * FROM {$prefix}providers WHERE enabled = 1 ORDER BY is_default DESC, id LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);
        if (!$provider) {
            throw new \RuntimeException('No AI provider configured - add one in the admin panel.');
        }
        $manager = new AiManager(['default' => $provider['slug'], 'providers' => [
            $provider['slug'] => [
                'driver' => $provider['driver'],
                'api_key' => $provider['api_key'],
                'model' => $provider['model'],
                'base_url' => $provider['base_url'] ?: null,
                'name' => $provider['slug'],
            ],
        ]]);

        $registry = new ToolRegistry();
        $registry->register(new EscalateTool());
        foreach ($pdo->query("SELECT * FROM {$prefix}tools WHERE enabled = 1") as $row) {
            try {
                $registry->register(SqlTool::fromDefinition([
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'parameters' => json_decode($row['parameters'], true) ?: [],
                    'sql' => $row['sql'],
                    'columns' => json_decode($row['columns'], true) ?: [],
                    'context' => json_decode((string) $row['context'], true) ?: [],
                    'max_rows' => (int) $row['max_rows'],
                ], function (string $sql, array $bindings) use ($pdo) {
                    $st = $pdo->prepare($sql);
                    foreach ($bindings as $k => $v) {
                        $st->bindValue(':'.$k, $v);
                    }
                    $st->execute();
                    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                }));
            } catch (\Throwable $e) {
                // invalid tool rows are skipped, never fatal
            }
        }

        $base = "You are a helpful, concise customer support agent. Use the provided tools to look up real data before answering; never invent order or account details. If a tool errors, apologise briefly and offer to escalate.";
        // folder by folder, in the owner's order - see Storage\Rules
        $system = (new \Banimark\Storage\Rules($pdo, $prefix))->systemInstruction($base);

        $settings = (new Settings($pdo))->all();
        return new Engine($manager->driver(), $registry, [
            'system' => $system."\n".\Banimark\Ai\Behaviour::systemLines($settings),
            'temperature' => (float) $provider['temperature'],
            'max_tokens' => \Banimark\Ai\Behaviour::maxTokens($settings),
            'max_iterations' => 4,
        ]);
    }
}
