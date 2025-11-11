# Flowlog Laravel SDK

Laravel SDK for Flowlog - Async logging with automatic context
extraction, exception reporting, and configurable event logging.

## Requirements

- PHP 8.2 or higher
- Laravel 10, 11, or 12

## Features

- 🔄 **Async Logging**: Logs are batched and sent asynchronously via
  Laravel queues
- 🎯 **Automatic Context Extraction**: Automatically extracts user,
  request, route, and trace information
- 🚨 **Exception Reporting**: Automatically reports exceptions with
  full context
- 📊 **Query Logging**: Log slow and failed database queries
  (configurable)
- 🌐 **HTTP Logging**: Log HTTP requests and responses (configurable)
- ⚙️ **Job/Queue Logging**: Log job processing, completion, and
  failures (enabled by default)
- 🎛️ **Fully Configurable**: Enable/disable features via config file

## Installation

### 1. Install via Composer

```bash
composer require flowlog/flowlog-laravel
```

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=flowlog-config
```

### 3. Configure Environment Variables

Add to your `.env` file:

#### Required Variables

```env
FLOWLOG_API_KEY=your-api-key-here
```

#### Optional Variables

```env
# API Configuration
FLOWLOG_API_URL=https://flowlog.io/api/v1/logs
FLOWLOG_SERVICE=my-laravel-app  # Defaults to APP_NAME if not set
FLOWLOG_ENV=production           # Defaults to APP_ENV if not set

# Batch Configuration
FLOWLOG_BATCH_SIZE=50            # Max logs per batch (default: 50)
FLOWLOG_BATCH_INTERVAL=5         # Flush interval in seconds (default: 5)
FLOWLOG_BATCH_MAX_SIZE=65536     # Max batch size in bytes, 64KB (default: 65536)

# Feature Toggles
FLOWLOG_QUERY_LOGGING=false      # Enable slow/failed query logging (default: false)
FLOWLOG_HTTP_LOGGING=false       # Enable HTTP request/response logging (default: false)
FLOWLOG_JOB_LOGGING=true         # Enable job/queue logging (default: true)
FLOWLOG_EXCEPTION_REPORTING=true # Auto-report exceptions (default: true)

# Query Logging Configuration
FLOWLOG_QUERY_SLOW_THRESHOLD=1000  # Log queries slower than this in ms (default: 1000)
FLOWLOG_QUERY_LOG_FAILED=true      # Log failed queries (default: true)
FLOWLOG_QUERY_LOG_ALL=false        # Log all queries, not just slow/failed (default: false)

# HTTP Logging Configuration
FLOWLOG_HTTP_LOG_HEADERS=false    # Include headers in HTTP logs (default: false)

# Exception Reporting Configuration
FLOWLOG_EXCEPTION_LEVEL=error     # Exception log level: 'error' or 'critical' (default: 'error')

# Queue Configuration
FLOWLOG_QUEUE_CONNECTION=sync     # Queue connection (default: sync, uses QUEUE_CONNECTION if set)
FLOWLOG_QUEUE_NAME=default        # Queue name (default: 'default')
FLOWLOG_QUEUE_TRIES=3             # Number of retry attempts (default: 3)
FLOWLOG_QUEUE_DEBOUNCE_DELAY=3    # Seconds to wait before sending (default: 3)
# Note: FLOWLOG_QUEUE_BACKOFF is configured in config/flowlog.php as an array [1, 5, 10]
# representing retry delays in seconds. Cannot be set via .env directly.

# Chunking Configuration
FLOWLOG_CHUNK_SIZE=100            # Number of logs per chunk when sending (default: 100)

# Fallback Log Channel
FLOWLOG_FALLBACK_LOG_CHANNEL=single  # Channel for internal SDK logs (default: 'single')
```

### 4. Add Flowlog Channel to Logging Config

Edit `config/logging.php` and add the Flowlog channel to your stack:

```php
'channels' => [
    'stack' => [
        'driver' => 'stack',
        'channels' => ['daily', 'flowlog'], // Add 'flowlog' here
        'ignore_exceptions' => false,
    ],

    // ... other channels

    'flowlog' => [
        'driver' => 'flowlog',
    ],
],
```

### 5. (Optional) Add Middleware for Iteration/Trace IDs

**Important**: The service provider automatically registers
`FlowlogTerminatingMiddleware` for flushing logs at the end of
requests. However, if you want automatic iteration key and trace ID
generation, or automatic handling of the `X-Flowlog-Ignore` header,
you need to manually register `FlowlogMiddleware`.

**Note**: The `FlowlogMiddleware` handles the `X-Flowlog-Ignore`
header to prevent infinite loops. When this header is present, logging
will be skipped for that request. Without this middleware, you can
still use the guard programmatically (see "Preventing Infinite Loops"
section).

#### Laravel 11 and 12

Add the middleware to `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->web(append: [
        \Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class,
    ]);

    $middleware->api(append: [
        \Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class,
    ]);
})
```

#### Laravel 10

Add the middleware to `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ... existing middleware
        \Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class,
    ],

    'api' => [
        // ... existing middleware
        \Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class,
    ],
];
```

## Usage

### Basic Logging

Use Laravel's standard logging methods - they will automatically be
sent to Flowlog if the channel is in your stack:

```php
use Illuminate\Support\Facades\Log;

Log::info('User logged in', ['user_id' => 123]);
Log::error('Payment failed', ['order_id' => 456]);
Log::warning('Slow query detected');
```

### Using the Flowlog Facade

For more control, use the Flowlog facade:

```php
use Flowlog\FlowlogLaravel\Facades\Flowlog;

// Basic logging
Flowlog::info('Application started');
Flowlog::error('Something went wrong', ['error_code' => 'E001']);
Flowlog::warn('Deprecated API used');
Flowlog::debug('Debug information');
Flowlog::critical('System failure');

// Set iteration key for grouping related logs
Flowlog::setIterationKey('request-123')->info('Processing request');

// Set trace ID for request tracing
Flowlog::setTraceId('trace-456')->info('Request started');

// Add context that will be included in all subsequent logs
Flowlog::withContext(['feature' => 'checkout'])->info('Checkout started');
Flowlog::info('Payment processed'); // Will include 'feature' => 'checkout'

// Report exceptions
try {
    // some code
} catch (\Exception $e) {
    Flowlog::reportException($e, ['additional' => 'context']);
}
```

### Using Helper Function

You can also use the helper function:

```php
flowlog()->info('Hello from Flowlog');
flowlog()->error('An error occurred', ['details' => '...']);
```

## Configuration

All configuration is in `config/flowlog.php`. Key options:

### API Configuration

```php
'api_url' => env('FLOWLOG_API_URL', 'https://flowlog.io/api/v1/logs'),
'api_key' => env('FLOWLOG_API_KEY'),
'service' => env('FLOWLOG_SERVICE', config('app.name')),
'env' => env('FLOWLOG_ENV', env('APP_ENV', 'production')),
```

### Batch Configuration

```php
'batch' => [
    'size' => 50,              // Max logs per batch
    'interval' => 5,           // Flush interval in seconds
    'max_size_bytes' => 65536, // Max batch size in bytes (64KB)
],
```

### Feature Toggles

```php
'features' => [
    'query_logging' => false,        // Log slow/failed queries
    'http_logging' => false,         // Log HTTP requests/responses
    'job_logging' => true,           // Log job events (enabled by default)
    'exception_reporting' => true,   // Auto-report exceptions (enabled by default)
],
```

### Query Logging

```php
'query' => [
    'slow_threshold_ms' => 1000, // Log queries slower than this
    'log_failed' => true,         // Log failed queries
],
```

### HTTP Logging

```php
'http' => [
    'exclude_routes' => ['health', 'nova/*'], // Routes to exclude
    'log_headers' => false,                    // Include headers in logs
    'sanitize_headers' => ['authorization', 'cookie', 'x-api-key'],
],
```

### Exception Reporting

```php
'exceptions' => [
    'dont_report' => [
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Validation\ValidationException::class,
        // Add exceptions you don't want to report
    ],
    'level' => 'error', // 'error' or 'critical'
],
```

## Automatic Context Extraction

The SDK automatically extracts the following context:

- **User ID**: From authenticated user (`auth()->id()`)
- **Request ID**: From `X-Request-ID` header or generated UUID
- **Trace ID**: From `X-Trace-Id` header or generated UUID (if
  middleware is used)
- **Iteration Key**: From `X-Iteration-Key` header or request context
  (if middleware is used)
- **Route Information**: Route name, action, URI, parameters
- **HTTP Information**: Method, URL, path, IP, user agent
- **Session ID**: If session is started

## Preventing Infinite Loops

The SDK includes built-in guards to prevent infinite loops when
logging. The guard mechanism uses the `X-Flowlog-Ignore` header or
programmatic guards.

### Using the X-Flowlog-Ignore Header

Set the `X-Flowlog-Ignore` header on HTTP requests to prevent logging:

```php
// In your HTTP client request
$response = Http::withHeaders([
    'X-Flowlog-Ignore' => '1',
])->post('https://api.example.com/endpoint');
```

When this header is present, the SDK will:

- Skip HTTP request/response logging
- Skip query logging
- Skip job event logging
- Prevent log flushing

### Programmatic Guard Control

You can also control the guard programmatically:

```php
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;

// Set ignore guard
FlowlogGuard::setIgnore(true);

try {
    // Your code here - logging will be skipped
} finally {
    // Reset guard
    FlowlogGuard::setIgnore(false);
}
```

### ProcessLogJob Integration

If you're using `ProcessLogJob` to process incoming logs, you should
set the guard when the job starts:

```php
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;

class ProcessLogJob implements ShouldQueue
{
    public function handle(): void
    {
        FlowlogGuard::whileInProcessLogJob(function () {
            // Your job logic here
            // Queries and job events won't be logged
        });
    }
}
```

Alternatively, you can set the `X-Flowlog-Ignore` header on the HTTP
request that triggers the job.

**Note**: The SDK automatically sets `X-Flowlog-Ignore` when
`SendLogsJob` makes HTTP requests to the Flowlog API to prevent
infinite loops.

## Queue Configuration

Logs are sent asynchronously via Laravel queues. Configure in
`config/flowlog.php`:

```php
'queue' => [
    'connection' => null,  // null = use default connection
    'queue' => 'default',
    'tries' => 3,
    'backoff' => [1, 5, 10], // Retry delays in seconds
],
```

Make sure your queue worker is running:

```bash
php artisan queue:work
```

## Testing

When running tests, the Flowlog channel will be automatically disabled
if the API key is not set. You can also mock the Flowlog facade in
tests:

```php
use Flowlog\FlowlogLaravel\Facades\Flowlog;

Flowlog::shouldReceive('info')
    ->once()
    ->with('Test message');
```

### Preventing Logging in Tests

To prevent logging during tests, you can set the ignore guard:

```php
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;

beforeEach(function () {
    FlowlogGuard::setIgnore(true);
});

afterEach(function () {
    FlowlogGuard::setIgnore(false);
});
```

Or use the `X-Flowlog-Ignore` header in your test HTTP requests:

```php
$response = $this->withHeaders([
    'X-Flowlog-Ignore' => '1',
])->get('/api/endpoint');
```

## License

MIT
