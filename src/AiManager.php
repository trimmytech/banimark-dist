<?php

namespace Banimark;

use Banimark\Contracts\AiDriver;
use Banimark\Drivers\AnthropicDriver;
use Banimark\Drivers\GeminiDriver;
use Banimark\Drivers\OpenAiCompatDriver;

/**
 * Turns provider config into drivers. Providers config shape:
 *
 * 'providers' => [
 *   'gemini'      => ['driver' => 'gemini',       'api_key' => ..., 'model' => ...],
 *   'openai'      => ['driver' => 'openai-compat','api_key' => ..., 'model' => ..., 'base_url' => 'https://api.openai.com/v1'],
 *   'deepseek'    => ['driver' => 'openai-compat','api_key' => ..., 'model' => ..., 'base_url' => 'https://api.deepseek.com'],
 *   'siliconflow' => ['driver' => 'openai-compat','api_key' => ..., 'model' => ..., 'base_url' => 'https://api.siliconflow.com/v1'],
 *   'anthropic'   => ['driver' => 'anthropic',    'api_key' => ..., 'model' => ...],
 * ],
 * 'default' => 'gemini'
 *
 * Custom driver classes register via extend(). Hosts (or the admin panel)
 * can override config per call - the panel's provider table maps 1:1 onto
 * this shape.
 */
class AiManager
{
    /** @var array<string, callable(array, ?callable): AiDriver> */
    private array $factories = [];
    /** @var array<string, AiDriver> */
    private array $resolved = [];

    public function __construct(private array $config = [], private $transport = null)
    {
        $this->factories = [
            'gemini' => fn (array $c, $t) => new GeminiDriver($c, $t),
            'openai-compat' => fn (array $c, $t) => new OpenAiCompatDriver($c, $t),
            'anthropic' => fn (array $c, $t) => new AnthropicDriver($c, $t),
        ];
    }

    public function extend(string $driver, callable $factory): void
    {
        $this->factories[$driver] = $factory;
    }

    public function driver(?string $name = null): AiDriver
    {
        $name = $name ?: (string) ($this->config['default'] ?? 'gemini');
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }
        $cfg = $this->config['providers'][$name] ?? null;
        if ($cfg === null) {
            throw new \InvalidArgumentException("Unknown AI provider [{$name}] - configure it under supportdesk.providers.");
        }
        $driverKey = (string) ($cfg['driver'] ?? $name);
        $factory = $this->factories[$driverKey] ?? null;
        if ($factory === null) {
            throw new \InvalidArgumentException("Unknown AI driver [{$driverKey}] for provider [{$name}].");
        }
        $cfg['name'] = $cfg['name'] ?? $name;

        return $this->resolved[$name] = $factory($cfg, $this->transport);
    }

    /** @return string[] configured provider slugs (for the panel dropdown) */
    public function available(): array
    {
        return array_keys((array) ($this->config['providers'] ?? []));
    }
}
