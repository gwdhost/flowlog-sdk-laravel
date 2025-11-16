<?php

namespace Flowlog\FlowlogLaravel\Context;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContextExtractor
{
    /**
     * Extract all available context from the current request.
     */
    public function extract(): array
    {
        $context = [];

        if (app()->runningInConsole()) {
            return $this->extractConsoleContext();
        }

        $request = request();

        if ($request) {
            $context = array_merge($context, $this->extractRequestContext($request));
            $context = array_merge($context, $this->extractRouteContext($request));
        }

        return $context;
    }

    /**
     * Extract context from console/artisan commands.
     */
    protected function extractConsoleContext(): array
    {
        return [
            'type' => 'console',
            'command' => $_SERVER['argv'][1] ?? null,
        ];
    }

    /**
     * Extract context from HTTP request.
     */
    protected function extractRequestContext(Request $request): array
    {
        $context = [
            'http_method' => $request->method(),
            'http_host' => $request->host(),
            'http_path' => $request->path(),
            'http_user_agent' => $request->userAgent(),
        ];

        // Extract request ID from header or generate one
        $requestId = $request->header('X-Request-ID') ?? $request->header('X-Request-Id');
        if (empty($requestId)) {
            $requestId = (string) Str::uuid();
        }
        $context['request_id'] = $requestId;

        // Extract iteration key from FlowlogContext first, then fall back to headers
        $iterationKey = FlowlogContext::getIterationKey()
            ?? $request->header('X-Iteration-Key')
            ?? $request->header('X-Flowlog-Iteration-Key');
        if (!empty($iterationKey)) {
            $context['iteration_key'] = $iterationKey;
        }

        // Extract trace ID from FlowlogContext first, then fall back to headers or generate one
        $traceId = FlowlogContext::getTraceId()
            ?? $request->header('X-Trace-Id')
            ?? $request->header('X-Trace-ID');
        if (empty($traceId)) {
            $traceId = (string) Str::uuid();
        }
        $context['trace_id'] = $traceId;

        return $context;
    }

    /**
     * Extract route context.
     */
    protected function extractRouteContext(Request $request): array
    {
        $context = [];
        $route = $request->route();

        if ($route) {
            $context['route_name'] = $route->getName();
            $context['route_action'] = $route->getActionName();
            $context['route_uri'] = $route->uri();
        }

        return $context;
    }

    /**
     * Sanitize parameters to avoid logging sensitive data.
     */
    protected function sanitizeParameters(array $parameters): array
    {
        $sensitive = ['password', 'token', 'secret', 'key', 'api_key', 'authorization'];

        return array_map(function ($value, $key) use ($sensitive) {
            if (in_array(strtolower($key), $sensitive)) {
                return '***';
            }

            return is_string($value) && strlen($value) > 100 ? substr($value, 0, 100).'...' : $value;
        }, $parameters, array_keys($parameters));
    }

    /**
     * Extract context for a specific exception.
     */
    public function extractExceptionContext(\Throwable $exception): array
    {
        $context = [
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'exception_code' => $exception->getCode(),
        ];

        // Add stack trace (limited to first 20 frames)
        $trace = $exception->getTrace();
        $context['exception_trace'] = array_slice($trace, 0, 20);

        // Merge with request context if available
        $context = array_merge($this->extract(), $context);

        return $context;
    }

    /**
     * Extract context for a database query.
     */
    public function extractQueryContext(array $queryData): array
    {
        return [
            'query_sql' => $queryData['sql'] ?? null,
            'query_bindings' => $queryData['bindings'] ?? [],
            'query_time_ms' => $queryData['time'] ?? null,
            'query_connection' => $queryData['connection'] ?? null,
        ];
    }

    /**
     * Extract context for an HTTP request/response.
     */
    public function extractHttpContext(Request $request, ?int $statusCode = null, ?float $executionTime = null): array
    {
        $context = $this->extractRequestContext($request);

        if ($statusCode !== null) {
            $context['http_status'] = $statusCode;
        }

        if ($executionTime !== null) {
            $context['http_execution_time_ms'] = round($executionTime * 1000, 2);
        }

        return $context;
    }

    /**
     * Extract context for a job/queue event.
     */
    public function extractJobContext(string $jobClass, string $queue, int $attempts, ?float $executionTime = null): array
    {
        $context = [
            'job_class' => $jobClass,
            'job_queue' => $queue,
            'job_attempts' => $attempts,
        ];

        if ($executionTime !== null) {
            $context['job_execution_time_ms'] = round($executionTime * 1000, 2);
        }

        // Include iteration key from FlowlogContext if available
        $iterationKey = FlowlogContext::getIterationKey();
        if (!empty($iterationKey)) {
            $context['iteration_key'] = $iterationKey;
        }

        // Include trace ID from FlowlogContext if available
        $traceId = FlowlogContext::getTraceId();
        if (!empty($traceId)) {
            $context['trace_id'] = $traceId;
        }

        return $context;
    }
}

