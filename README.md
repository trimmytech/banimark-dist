# Banimark

Self-hosted AI engine for ANY PHP app - Laravel package or standalone (support desk, tools, and more as modules) — your data, your server, your AI key.

<!-- staff+escalation added -->
## Status: installable product, PILOT-PROVEN (steps 1-6a; licensing pending)

Proven live in a real Laravel 12 app (AidaSuite): installed via composer path repo, org-scoped `search_tasks` tool built through the panel, real Gemini answering from the host's own task table, multi-turn memory, and human escalation - all working end to end.

Framework-agnostic AI core with a Laravel bridge:

- `Contracts\AiDriver` — one interface, every provider
- `Contracts\ToolContract` — the extension point businesses implement (or build no-code via the Tool Builder, step 3)
- Canonical DTOs: `AiRequest`, `AiReply`, `Message`, `ToolCall`, `ToolDefinition`
- Drivers: `GeminiDriver` (native REST), `OpenAiCompatDriver` (OpenAI / DeepSeek / SiliconFlow / Groq / local gateways via `base_url`), `AnthropicDriver`
- `AiManager` — config-driven provider registry (`driver('deepseek')`), custom drivers via `extend()`
- Zero runtime dependencies; HTTP transport is injectable (tests run without a network)

## Usage sketch

```php
$ai = new \Banimark\AiManager(require 'config/supportdesk.php');
$reply = $ai->driver()->generate(new AiRequest(
    messages: [Message::user('Where is my order ABC123?')],
    system: 'You are a support agent for Acme.',
    tools: [ToolDefinition::fromContract(new SearchOrderTool())],
));
if ($reply->wantsTools()) { /* run tools, append Message::toolResult(...), call again */ }
```

## Tests

composer install && composer test

## Roadmap

1. ✅ AiDriver core + drivers
2. ✅ Conversation engine - `Engine\Engine` (tool loop with iteration guard + forced final toolless pass, duplicate-call suppression, row-aware result trimming, tool errors fed back not fatal, sanitize hook, usage aggregation), `Engine\ConversationState` (storage-agnostic, array round-trip), `Tools\ToolRegistry`
3. ✅ Tool Builder core - `Tools\SqlTool` compiles an admin-authored definition (name, description, param specs, SELECT template, column whitelist, row cap) into a safe ToolContract: values always BOUND, `:_key` bindings come from host context so the model can never widen its scope, non-whitelisted columns stripped, unscoped queries refuse to run, db errors never leak. `Tools\SqlToolValidator` = read-only static gate (SELECT-only, no comments/semicolons/UNION/write verbs, every placeholder declared)
4. ✅ Chat widget + signed identity - `Identity\VisitorToken` (HMAC handshake: host mints for logged-in users, endpoint verifies into tool context; invalid = silent anonymous, no oracle), `Http\ChatEndpoint` (framework-agnostic: sessions bound to identity so guessed ids never resume someone else's chat, length caps, history window, friendly failures that keep the message), `resources/widget/banimark-widget.js` (zero-dep vanilla JS in a closed Shadow DOM, served from the host's domain), Laravel routes `/banimark/widget.js` + `/banimark/chat` (per-IP throttle, config-injected styling)
5. Package admin panel (prefixed migrations: tickets, messages, rules, providers, widget)
6. ✅ Installer (licensing deferred) - `php artisan banimark:install` (publishes config, migrates, generates the identity secret into .env, interactive first-provider seeding with hidden key input, prints panel URL + embed snippets; idempotent) and `php artisan banimark:doctor` (tables, provider+key, secret, routes mounted, every enabled tool recompiled through the validator - the run-this-before-filing-a-ticket command). Licensing backbone (license-gated Composer repo, watermarking, fail-open phone-home) comes later.

## Install - plain PHP (no framework)

Drop one file in your web root:

```php
<?php // banimark.php
require __DIR__.'/vendor/autoload.php';
\Banimark\Standalone\App::run(__DIR__.'/banimark.config.php');
```

Open `banimark.php/install` in a browser: requirements check, database setup
(MySQL/MariaDB or SQLite), admin password, identity secret, optional first AI
provider - then it locks itself. Admin panel at `banimark.php/admin`, widget at
`banimark.php/widget.js`, same features as the Laravel panel (inbox + human
takeover, Tool Builder with save-time validation, rules, providers, widget
designer). Session auth + CSRF on every mutation.

## Install - Laravel

```
composer require banimark/banimark        # via path/VCS repo until the private repo exists
php artisan banimark:install
php artisan banimark:doctor
```
