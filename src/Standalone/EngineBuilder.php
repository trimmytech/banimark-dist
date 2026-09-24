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
    /**
     * The configured provider as a bare driver - no tools, no rules. The Tool
     * Builder's assistant needs the key, not the support persona.
     *
     * @return array{0: ?\Banimark\Contracts\AiDriver, 1: string, 2: string} driver, slug, model
     */
    public static function driver(\PDO $pdo, string $prefix = 'banimark_'): array
    {
        $row = $pdo->query("SELECT * FROM {$prefix}providers WHERE enabled = 1 ORDER BY is_default DESC, id LIMIT 1")
            ->fetch(\PDO::FETCH_ASSOC);
        if (!$row || trim((string) $row['api_key']) === '') {
            return [null, '', ''];
        }
        try {
            $manager = new AiManager(['default' => $row['slug'], 'providers' => [$row['slug'] => [
                'driver' => $row['driver'], 'api_key' => $row['api_key'], 'model' => $row['model'],
                'base_url' => $row['base_url'] ?: null, 'name' => $row['slug'],
            ]]]);
            return [$manager->driver(), (string) $row['slug'], (string) $row['model']];
        } catch (\Throwable $e) {
            return [null, '', ''];
        }
    }

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

        $settings = (new Settings($pdo))->all();
        // tools read from the owner's data connection, which may be a read-only
        // user, a replica, or another server entirely
        $runner = \Banimark\Storage\DataSource::runner(\Banimark\Storage\DataSource::pdo($settings, $pdo));
        $registry = new ToolRegistry();
        $registry->register(new EscalateTool(
            \Banimark\Ai\Behaviour::escalation($settings),
            \Banimark\Desk\BusinessHours::fromSettings($settings),
        ));
        foreach ($pdo->query("SELECT * FROM {$prefix}tools WHERE enabled = 1") as $row) {
            try {
                $registry->register(\Banimark\Tools\ToolFactory::make([
                    'name' => $row['name'],
                    'description' => $row['description'],
                    'parameters' => json_decode($row['parameters'], true) ?: [],
                    'sql' => $row['sql'],
                    'columns' => json_decode($row['columns'], true) ?: [],
                    'context' => json_decode((string) $row['context'], true) ?: [],
                    'max_rows' => (int) $row['max_rows'],
                    'kind' => $row['kind'] ?? 'sql',
                    'config' => json_decode((string) ($row['config'] ?? ''), true) ?: [],
                ], $runner));
            } catch (\Throwable $e) {
                // invalid tool rows are skipped, never fatal
            }
        }

        $base = "You are a helpful, concise customer support agent. Use the provided tools to look up real data before answering; never invent order or account details. If a tool errors, apologise briefly and offer to escalate.";
        // folder by folder, in the owner's order - see Storage\Rules
        $system = (new \Banimark\Storage\Rules($pdo, $prefix))->systemInstruction($base);

        return new Engine($manager->driver(), $registry, [
            'system' => $system."\n".\Banimark\Ai\Behaviour::systemLines($settings),
            'temperature' => (float) $provider['temperature'],
            'max_tokens' => \Banimark\Ai\Behaviour::maxTokens($settings),
            'max_iterations' => 4,
        ]);
    }
}
