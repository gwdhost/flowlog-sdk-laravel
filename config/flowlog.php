<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Flowlog API Configuration
    |--------------------------------------------------------------------------
    |
    | Configure your Flowlog API endpoint and authentication.
    |
    */

    'api_url' => env('FLOWLOG_API_URL', 'https://api.flowlog.io/api/v1/logs'),
    'api_key' => env('FLOWLOG_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Service Configuration
    |--------------------------------------------------------------------------
    |
    | The service name identifies your application in Flowlog.
    | Defaults to your application name.
    |
    */

    'service' => env('FLOWLOG_SERVICE', config('app.name', 'laravel')),

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | The environment for your logs. Automatically detected from APP_ENV.
    |
    */

    'env' => env('FLOWLOG_ENV', env('APP_ENV', 'production')),

    /**
     * Fallback log channel
     * 
     * @var string
     */
    'fallback_log_channel' => env('FLOWLOG_FALLBACK_LOG_CHANNEL', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Batch Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how logs are batched before being sent to Flowlog.
    |
    */

    'batch' => [
        'size' => env('FLOWLOG_BATCH_SIZE', 50),
        'interval' => env('FLOWLOG_BATCH_INTERVAL', 5), // seconds
        'max_size_bytes' => env('FLOWLOG_BATCH_MAX_SIZE', 64 * 1024), // 64KB
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Enable or disable automatic logging features.
    |
    */

    'features' => [
        'query_logging' => env('FLOWLOG_QUERY_LOGGING', false),
        'http_logging' => env('FLOWLOG_HTTP_LOGGING', false),
        'job_logging' => env('FLOWLOG_JOB_LOGGING', true),
        'exception_reporting' => env('FLOWLOG_EXCEPTION_REPORTING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure slow query detection and logging.
    |
    */

    'query' => [
        'slow_threshold_ms' => env('FLOWLOG_QUERY_SLOW_THRESHOLD', 1000),
        'log_failed' => env('FLOWLOG_QUERY_LOG_FAILED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure HTTP request/response logging.
    |
    */

    'http' => [
        'exclude_routes' => [
            // Add route patterns to exclude from logging
            // Example: 'health', 'nova/*', 'api/v1/logs'
        ],
        'log_headers' => env('FLOWLOG_HTTP_LOG_HEADERS', false),
        'sanitize_headers' => [
            'authorization',
            'cookie',
            'x-api-key',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which jobs should be excluded from logging.
    |
    */

    'jobs' => [
        'exclude_jobs' => [
            // Add job class names to exclude from logging
            // Example: \Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exception Reporting Configuration
    |--------------------------------------------------------------------------
    |
    | Configure which exceptions should be reported to Flowlog.
    |
    */

    'exceptions' => [
        'dont_report' => [
            \Illuminate\Auth\AuthenticationException::class,
            \Illuminate\Auth\Access\AuthorizationException::class,
            \Illuminate\Database\Eloquent\ModelNotFoundException::class,
            \Illuminate\Validation\ValidationException::class,
            \Symfony\Component\HttpKernel\Exception\HttpException::class,
        ],
        'level' => env('FLOWLOG_EXCEPTION_LEVEL', 'error'), // error or critical
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the queue connection and settings for async log delivery.
    |
    */

    'queue' => [
        'connection' => env('FLOWLOG_QUEUE_CONNECTION', null), // null = default
        'queue' => env('FLOWLOG_QUEUE_NAME', 'default'),
        'tries' => env('FLOWLOG_QUEUE_TRIES', 3),
        'backoff' => env('FLOWLOG_QUEUE_BACKOFF', [1, 5, 10]), // seconds
        'debounce_delay' => env('FLOWLOG_QUEUE_DEBOUNCE_DELAY', 3), // seconds to wait before sending
    ],
];

