<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class QueryListener
{
    protected ContextExtractor $contextExtractor;
    
    /**
     * Track the currently processing job class name (for queue workers).
     * This is set by JobListener when a job starts processing.
     */
    protected static ?string $currentJobClass = null;

    public function __construct()
    {
        $this->contextExtractor = new ContextExtractor();
    }
    
    /**
     * Set the currently processing job class (called by JobListener).
     */
    public static function setCurrentJobClass(?string $jobClass): void
    {
        self::$currentJobClass = $jobClass;
    }
    
    /**
     * Get the currently processing job class.
     */
    public static function getCurrentJobClass(): ?string
    {
        return self::$currentJobClass;
    }

    /**
     * Handle the query executed event.
     */
    public function handle(QueryExecuted $event): void
    {
        // Block all queries during sending operations to prevent infinite loops
        if (FlowlogGuard::isSending()) {
            return;
        }

        // Block all queries during cache operations to prevent infinite loops
        if (FlowlogGuard::isCaching()) {
            return;
        }

        // Don't log queries related to flowlog:batched-logs cache
        if ($this->isBatchedLogsCacheQuery($event)) {
            return;
        }

        // Prevent infinite loops: block queries during SendLogsJob execution
        if (FlowlogGuard::inSendLogsJob()) {
            // Don't log queries related to Flowlog cache operations during SendLogsJob
            if (FlowlogGuard::isFlowlogQuery($event->sql)) {
                return;
            }
            // Also block all queries during SendLogsJob to prevent loops
            return;
        }

        // Prevent infinite loops: block queries during ProcessLogJob execution
        // ProcessLogJob processes incoming logs and shouldn't have its queries logged
        // Check both the guard (for same-process) and current job class (for async queue workers)
        if (FlowlogGuard::inProcessLogJob() || $this->isProcessLogJobCurrentlyRunning()) {
            return;
        }

        // Check if this is a cache-related query (database cache driver)
        if ($this->isCacheQuery($event)) {
            return;
        }

        // Don't log queries from Flowlog API endpoints (/api/v1/logs)
        if ($this->isFlowlogApiRequest()) {
            return;
        }

        // Don't log queries from queue worker operations (jobs table queries)
        if ($this->isQueueWorkerQuery($event)) {
            return;
        }

        // Don't log queries related to log_events table (ProcessLogJob inserts into this)
        // This prevents loops where ProcessLogJob inserts logs, which get logged, which get sent back
        if ($this->isLogEventsQuery($event)) {
            return;
        }

        $logAll = config('flowlog.query.log_all', false);
        $slowThreshold = config('flowlog.query.slow_threshold_ms', 1000);
        $timeMs = $event->time;

        // Log all queries if enabled
        if ($logAll) {
            $this->logQuery($event, $timeMs);
            return;
        }

        // Log slow queries
        if ($timeMs >= $slowThreshold) {
            $this->logSlowQuery($event, $timeMs);
        }

        // Note: Failed queries are not reliably detected via QueryExecuted event
        // Failed queries are typically caught by exception handlers instead
        // If you need to log failed queries, enable exception reporting
    }

    /**
     * Log a query (all queries when log_all is enabled).
     */
    protected function logQuery(QueryExecuted $event, float $timeMs): void
    {
        $context = $this->contextExtractor->extract();
        $queryContext = $this->contextExtractor->extractQueryContext([
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time' => $timeMs,
            'connection' => $event->connectionName,
        ]);

        $context = array_merge($context, $queryContext);

        $message = sprintf(
            'Query executed: %s',
            $this->formatSql($event->sql)
        );

        Log::channel('flowlog')->info($message, $context);
    }

    /**
     * Log a slow query.
     */
    protected function logSlowQuery(QueryExecuted $event, float $timeMs): void
    {
        $context = $this->contextExtractor->extract();
        $queryContext = $this->contextExtractor->extractQueryContext([
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time' => $timeMs,
            'connection' => $event->connectionName,
        ]);

        $context = array_merge($context, $queryContext);

        $message = sprintf(
            'Slow query detected: %s',
            $this->formatSql($event->sql)
        );

        Log::channel('flowlog')->warning($message, $context);
    }

    /**
     * Log a failed query.
     */
    protected function logFailedQuery(QueryExecuted $event): void
    {
        if (! config('flowlog.query.log_failed', true)) {
            return;
        }

        $context = $this->contextExtractor->extract();
        $queryContext = $this->contextExtractor->extractQueryContext([
            'sql' => $event->sql,
            'bindings' => $event->bindings,
            'time' => $event->time,
            'connection' => $event->connectionName,
        ]);

        $context = array_merge($context, $queryContext);

        $message = sprintf(
            'Query failed | SQL: %s',
            $this->formatSql($event->sql)
        );

        Log::channel('flowlog')->error($message, $context);
    }

    /**
     * Format SQL for logging (truncate if too long).
     */
    protected function formatSql(string $sql): string
    {
        if (strlen($sql) > 500) {
            return substr($sql, 0, 500).'...';
        }

        return $sql;
    }

    /**
     * Check if a query is related to the flowlog:batched-logs cache.
     */
    protected function isBatchedLogsCacheQuery(QueryExecuted $event): bool
    {
        $sql = strtolower($event->sql);
        $cacheKeyPattern = 'flowlog:batched-logs';

        // Check if SQL contains the cache key pattern
        if (str_contains($sql, strtolower($cacheKeyPattern))) {
            return true;
        }

        // Check if any bindings contain the cache key pattern
        foreach ($event->bindings as $binding) {
            if (is_string($binding) && str_contains($binding, $cacheKeyPattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a query is a cache-related query (database cache driver).
     */
    protected function isCacheQuery(QueryExecuted $event): bool
    {
        $sql = strtolower($event->sql);
        $connectionName = strtolower($event->connectionName ?? '');

        // Check if using database cache driver (connection name often contains 'cache')
        if (str_contains($connectionName, 'cache')) {
            return true;
        }

        // Check for cache table patterns (Laravel's default cache table name)
        $cacheTablePatterns = [
            'cache',
            'cache_locks',
            'cache_tags',
        ];

        foreach ($cacheTablePatterns as $pattern) {
            if (str_contains($sql, $pattern)) {
                // Additional check: verify it's actually a cache table query
                // by checking for common cache operations
                $cacheOperations = ['select', 'insert', 'update', 'delete'];
                foreach ($cacheOperations as $operation) {
                    if (preg_match('/\b' . $operation . '\s+.*\b' . $pattern . '\b/i', $sql)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if the current request is to a Flowlog API endpoint.
     */
    protected function isFlowlogApiRequest(): bool
    {
        // Only check in HTTP context (not console)
        if (app()->runningInConsole()) {
            return false;
        }

        $request = request();
        if (!$request) {
            return false;
        }

        // Check if ignore guard is set (e.g., via X-Flowlog-Ignore header)
        return FlowlogGuard::shouldIgnore();
    }

    /**
     * Check if a query is from queue worker operations.
     * These queries should not be logged as they're internal Laravel operations.
     */
    protected function isQueueWorkerQuery(QueryExecuted $event): bool
    {
        $sql = strtolower($event->sql);

        // Check for jobs table queries (queue worker operations)
        $jobsTablePatterns = [
            'from `jobs`',
            'into `jobs`',
            'update `jobs`',
            'delete from `jobs`',
        ];

        foreach ($jobsTablePatterns as $pattern) {
            if (str_contains($sql, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a query is related to log_events table.
     * These queries should not be logged as they're from ProcessLogJob inserting logs.
     */
    protected function isLogEventsQuery(QueryExecuted $event): bool
    {
        $sql = strtolower($event->sql);

        // Check for log_events table queries (ProcessLogJob operations)
        $logEventsTablePatterns = [
            'from `log_events`',
            'into `log_events`',
            'insert into `log_events`',
            'update `log_events`',
            'delete from `log_events`',
            'from log_events',
            'into log_events',
            'insert into log_events',
            'update log_events',
            'delete from log_events',
        ];

        foreach ($logEventsTablePatterns as $pattern) {
            if (str_contains($sql, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if ProcessLogJob is currently running in a queue worker.
     * This works across different processes/requests.
     */
    protected function isProcessLogJobCurrentlyRunning(): bool
    {
        // Only check in console/queue worker context
        if (!app()->runningInConsole()) {
            return false;
        }

        $currentJobClass = self::getCurrentJobClass();
        
        // Check if current job is ProcessLogJob
        return $currentJobClass !== null && (
            str_contains($currentJobClass, 'ProcessLogJob') ||
            $currentJobClass === 'App\Jobs\ProcessLogJob'
        );
    }
}

