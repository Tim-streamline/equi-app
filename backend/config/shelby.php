<?php

return [
    /* Which AI answers in the app: "openai" or "anthropic". The admin test page can try both. */
    'provider' => env('SHELBY_PROVIDER', 'openai'),

    'openai' => [
        // Falls back to the feed scanner key, so Shelby works with the existing OpenAI account.
        'api_key' => env('SHELBY_OPENAI_API_KEY', env('FEED_SCANNER_OPENAI_API_KEY')),
        'model' => env('SHELBY_OPENAI_MODEL', 'gpt-4.1'),
    ],

    'anthropic' => [
        'api_key' => env('SHELBY_ANTHROPIC_API_KEY'),
        'model' => env('SHELBY_ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
    ],

    'timeout' => (int) env('SHELBY_TIMEOUT', 45),
    'max_output_tokens' => 700,
    /* Earlier messages from today's chat that are sent along for context. Older chats are never used as memory. */
    'history_messages' => 16,
    /* Questions per owner per calendar day, across all horses. */
    'daily_limit' => (int) env('SHELBY_DAILY_LIMIT', 40),
    'timezone' => 'Europe/Amsterdam',
];
