<?php

use App\Support\ErrorTracking;

return [
    'dsn' => env('BETTER_STACK_DSN'),
    'environment' => env('BETTER_STACK_ENVIRONMENT', env('APP_ENV', 'production')),
    'release' => env('BETTER_STACK_RELEASE') ?: (is_file(base_path('RELEASE_ID')) ? trim(file_get_contents(base_path('RELEASE_ID'))) : null),
    'send_default_pii' => false,
    'max_request_body_size' => 'none',
    'max_breadcrumbs' => 0,
    'context_lines' => 0,
    'attach_stacktrace' => true,
    'sample_rate' => 1.0,
    'traces_sample_rate' => 0.0,
    'profiles_sample_rate' => 0.0,
    'enable_logs' => false,
    'enable_metrics' => false,
    'before_send' => [ErrorTracking::class, 'sanitize'],
    'breadcrumbs' => [
        'logs' => false,
        'cache' => false,
        'sql_queries' => false,
        'sql_bindings' => false,
        'queue_info' => false,
        'command_info' => false,
        'http_client_requests' => false,
        'notifications' => false,
    ],
];
