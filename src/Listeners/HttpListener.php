<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Illuminate\Foundation\Http\Events\RequestHandled;
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
     * Handle the RequestHandled event.
     * This is the proper Laravel way to listen to HTTP requests in versions 10, 11, and 12.
     */
    public function handle(RequestHandled $event): void
    {
        $this->logRequest($event->request, $event->response);
    }

    /**
     * Register HTTP event listeners.
     * This method is kept for backward compatibility but is no longer needed
     * when using the handle() method with RequestHandled event.
     */
    public function register(): void
    {
        // This method is deprecated. Use Event::listen(RequestHandled::class, HttpListener::class)
        // in the service provider instead, or let Laravel auto-discover the listener.
    }

    /**
     * Log HTTP request and response.
     */
    protected function logRequest(Request $request, ?Response $response = null): void
    {
        // Don't log console/artisan commands
        if (app()->runningInConsole()) {
            return;
        }

        if (! $request || $request->method() == 'OPTIONS') {
            return;
        }

        // If it is not a route request, return
        if (! $request->route()) {
            return;
        }

        // Check if route should be excluded
        if ($this->shouldExcludeRoute($request)) {
            return;
        }

        $startTime = $this->getStartTime($request);
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        // Get status code from response if available
        $statusCode = null;
        try {
            if ($response && method_exists($response, 'getStatusCode')) {
                $statusCode = $response->getStatusCode();
            } else {
                // Fallback: try to get from request attributes (set by middleware)
                $statusCode = $request->attributes->get('_response_status');
            }
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
        $requestId = $this->getRequestIdentifier($request);
        
        if (! isset($this->startTimes[$requestId])) {
            $this->startTimes[$requestId] = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        }

        return $this->startTimes[$requestId] ?? null;
    }

    /**
     * Get a unique identifier for the request.
     */
    protected function getRequestIdentifier(Request $request): string
    {
        // First, try to get request ID from headers or attributes
        $requestId = $request->header('X-Request-ID') 
            ?? $request->header('X-Request-Id')
            ?? $request->attributes->get('_request_id');
        
        if ($requestId) {
            return (string) $requestId;
        }
        
        // Try to use fingerprint if route is available
        try {
            if ($request->route()) {
                return $request->fingerprint();
            }
        } catch (\RuntimeException $e) {
            // Route unavailable, fall through to fallback
        } catch (\Exception $e) {
            // Other exceptions, fall through to fallback
        }
        
        // Fallback: use method + path + IP + timestamp as identifier
        return md5(
            $request->method() . 
            $request->path() . 
            $request->ip() . 
            ($request->server('REQUEST_TIME_FLOAT') ?? microtime(true))
        );
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

