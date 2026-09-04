<?php

return [
    // which provider answers by default; the admin panel overrides at runtime
    'default' => env('BANIMARK_AI_PROVIDER', 'gemini'),

    'providers' => [
        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('BANIMARK_GEMINI_KEY', ''),
            'model' => env('BANIMARK_GEMINI_MODEL', 'gemini-2.5-flash'),
        ],
        'openai' => [
            'driver' => 'openai-compat',
            'api_key' => env('BANIMARK_OPENAI_KEY', ''),
            'model' => env('BANIMARK_OPENAI_MODEL', 'gpt-4o-mini'),
            'base_url' => 'https://api.openai.com/v1',
        ],
        'deepseek' => [
            'driver' => 'openai-compat',
            'api_key' => env('BANIMARK_DEEPSEEK_KEY', ''),
            'model' => env('BANIMARK_DEEPSEEK_MODEL', 'deepseek-chat'),
            'base_url' => 'https://api.deepseek.com',
        ],
        'siliconflow' => [
            'driver' => 'openai-compat',
            'api_key' => env('BANIMARK_SILICONFLOW_KEY', ''),
            'model' => env('BANIMARK_SILICONFLOW_MODEL', ''),
            'base_url' => 'https://api.siliconflow.com/v1',
        ],
        'anthropic' => [
            'driver' => 'anthropic',
            'api_key' => env('BANIMARK_ANTHROPIC_KEY', ''),
            'model' => env('BANIMARK_ANTHROPIC_MODEL', 'claude-sonnet-5'),
        ],
    ],

    // the shared secret the HOST uses to mint VisitorToken for logged-in
    // users (Banimark\Identity\VisitorToken::mint(['user_id' => ...], secret))
    'identity_secret' => env('BANIMARK_IDENTITY_SECRET', ''),

    // licensing: the key from your purchase email. The daily phone-home sends
    // ONLY the key, this site's URL and version numbers - never conversations,
    // API keys or data. Fail-open: an expired or unreachable license never
    // affects the widget or the panel, it only pauses your update channel.
    'license' => [
        'key' => env('BANIMARK_LICENSE_KEY', ''),
        'hq_url' => env('BANIMARK_HQ_URL', ''),
    ],

    'admin' => [
        'enabled' => true,
        'prefix' => 'banimark/admin',
        // Banimark has its OWN staff login (independent of the host site's
        // auth). The admin panel is ALWAYS mounted on 'web' (session + CSRF).
        // Add extra middleware here to further restrict who can even reach the
        // Banimark login (e.g. an IP allow-list). Do NOT put your app's 'auth'
        // here - Banimark provides its own gate.
        'extra_middleware' => [],
    ],

    'widget' => [
        'routes' => true,                 // auto-mount /banimark/widget.js + /banimark/chat
        'rate_per_minute' => 20,          // per-IP chat throttle
        'color' => '#6F04D9',
        'position' => 'right',            // right | left
        'title' => 'Support',
        'greeting' => 'Hi! How can we help you today?',
    ],

    'generation' => [
        'temperature' => (float) env('BANIMARK_TEMPERATURE', 0.4),
        'max_tokens' => (int) env('BANIMARK_MAX_TOKENS', 2048),
    ],
];
