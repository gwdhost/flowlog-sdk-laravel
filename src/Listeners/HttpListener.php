<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class HttpListener
{
    protected ContextExtractor $contextExtractor;
    protected array $startTimes = [];

    public function __construct()
    {
        $this->contextExtractor = new ContextExtractor();
    }

    /**
     * Register HTTP event listeners.
     */
    public function register(): void
    {
        // Listen to request events
        app()->terminating(function () {
            $this->logRequest();
        });
    }

    /**
     * Log HTTP request and response.
     */
    protected function logRequest(): void
    {
        $request = request();

        if (! $request) {
            return;
        }

        // Check if route should be excluded
        if ($this->shouldExcludeRoute($request)) {
            return;
        }

        $startTime = $this->getStartTime($request);
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        // Get status code from response if available
        // In terminating callback, we can't reliably get the response object
        // So we'll just log without status code or try to get it from the request
        $statusCode = null;
        try {
            // Try to get response status from request attributes (set by middleware)
            $statusCode = $request->attributes->get('_response_status') ?? $request->getStatusCode();
        } catch (\Exception $e) {
            // Status code not available
        }

        $context = $this->contextExtractor->extractHttpContext($request, $statusCode, $executionTime);

        // Add request headers if configured
        if (config('flowlog.http.log_headers', false)) {
            $context['http_headers'] = $this->sanitizeHeaders($request->headers->all());
        }

        $message = sprintf(
            $statusCode ? '%s %s - %s' : '%s %s',
            $request->method(),
            $request->path(),
            $statusCode ?? 'N/A'
        );

        // Determine log level based on status code
        $level = $this->getLogLevel($statusCode);

        Log::channel('flowlog')->{$level}($message, $context);
    }

    /**
     * Check if route should be excluded from logging.
     */
    protected function shouldExcludeRoute(Request $request): bool
    {
        $excludeRoutes = config('flowlog.http.exclude_routes', []);

        foreach ($excludeRoutes as $pattern) {
            if ($request->is($pattern) || $request->routeIs($pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get request start time.
     */
    protected function getStartTime(Request $request): ?float
    {
        if (! isset($this->startTimes[$request->fingerprint()])) {
            $this->startTimes[$request->fingerprint()] = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        }

        return $this->startTimes[$request->fingerprint()] ?? null;
    }

    /**
     * Sanitize headers to remove sensitive information.
     */
    protected function sanitizeHeaders(array $headers): array
    {
        $sanitize = config('flowlog.http.sanitize_headers', [
            'authorization',
            'cookie',
            'x-api-key',
        ]);

        foreach ($headers as $key => $value) {
            if (in_array(strtolower($key), $sanitize)) {
                $headers[$key] = ['***'];
            }
        }

        return $headers;
    }

    /**
     * Get log level based on HTTP status code.
     */
    protected function getLogLevel(?int $statusCode): string
    {
        if ($statusCode === null) {
            return 'info';
        }

        if ($statusCode >= 500) {
            return 'error';
        }

        if ($statusCode >= 400) {
            return 'warning';
        }

        return 'info';
    }
}

