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

        $settings = \Banimark\Laravel\BanimarkServiceProvider::settings();
        $registry = new ToolRegistry();
        $registry->register(new EscalateTool(
            \Banimark\Ai\Behaviour::escalation($settings),
            \Banimark\Desk\BusinessHours::fromSettings($settings),
        ));
        // tools read from the owner's data connection, which may be a read-only
        // user, a replica, or another server entirely
        $runner = \Banimark\Storage\DataSource::runner(self::dataPdo());
        // which tools, and how many, is the licence's call - decided in the core
        foreach (\Banimark\Tools\ToolFactory::forDesk(DB::connection()->getPdo(), 'banimark_', $settings, $runner,
            fn (string $name, string $why) => Log::warning('banimark: skipped invalid tool "'.$name.'": '.$why)) as $tool) {
            $registry->register($tool);
        }

        // Can the ACTIVE model read attachments, and has the owner left that on?
        // (see Standalone\EngineBuilder for the same decision)
        $readsFiles = \Banimark\Ai\ProviderPresets::readsAttachments($driverName, (string) ($providerConfig['model'] ?? ''))
            && \Banimark\Files\ModelInput::enabled($settings);
        $extra = [];
        if ($readsFiles) {
            try {
                $extra['attachmentResolver'] = \Banimark\Files\ModelInput::resolver(
                    new \Banimark\Storage\Attachments(\Illuminate\Support\Facades\DB::connection()->getPdo()),
                    app(\Banimark\Files\FileStore::class),
                    $settings,
                );
            } catch (\Throwable $e) {
                // no file store wired = nothing to hand over; the chat still answers
                $readsFiles = false;
            }
        }

        return new Engine($manager->driver(), $registry, [
            'system' => self::systemInstruction()."\n".\Banimark\Ai\Behaviour::systemLines($settings, $readsFiles),
            'temperature' => $temperature,
            'max_tokens' => \Banimark\Ai\Behaviour::maxTokens($settings),
            'max_iterations' => 4,
            'extra' => $extra,
        ]);
    }

    /** @return array{0:string,1:array,2:float} */
    /**
     * Where tools read from. Throws if the owner configured a connection that
     * cannot be opened - a tool must never quietly read the wrong database.
     */
    public static function dataPdo(): \PDO
    {
        return \Banimark\Storage\DataSource::pdo(
            \Banimark\Laravel\BanimarkServiceProvider::settings(),
            DB::connection()->getPdo(),
        );
    }

    /**
     * The configured provider as a bare driver - no tools, no rules. The Tool
     * Builder's assistant needs the key, not the support persona.
     *
     * @return array{0: ?\Banimark\Contracts\AiDriver, 1: string, 2: string} driver, slug, model
     */
    public static function driver(): array
    {
        [$slug, $config] = self::provider();
        if (trim((string) ($config['api_key'] ?? '')) === '') {
            return [null, '', ''];
        }
        try {
            $manager = new AiManager(['default' => $slug, 'providers' => [$slug => $config]]);
            return [$manager->driver(), $slug, (string) ($config['model'] ?? '')];
        } catch (\Throwable $e) {
            return [null, '', ''];
        }
    }

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
}
