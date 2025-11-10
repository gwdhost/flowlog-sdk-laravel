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

```env
FLOWLOG_API_URL=https://flowlog.io/api/v1/logs
FLOWLOG_API_KEY=your-api-key-here
FLOWLOG_SERVICE=my-laravel-app
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

If you want automatic iteration key and trace ID generation per
request, register the middleware based on your Laravel version:

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

## License

MIT
