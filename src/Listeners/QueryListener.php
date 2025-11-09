<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Log;

class QueryListener
{
    protected ContextExtractor $contextExtractor;

    public function __construct()
    {
        $this->contextExtractor = new ContextExtractor();
    }

    /**
     * Handle the query executed event.
     */
    public function handle(QueryExecuted $event): void
    {
        $slowThreshold = config('flowlog.query.slow_threshold_ms', 1000);
        $timeMs = $event->time;

        // Log slow queries
        if ($timeMs >= $slowThreshold) {
            $this->logSlowQuery($event, $timeMs);
        }

        // Log failed queries (if any error occurred)
        if (isset($event->result) && $event->result === false) {
            $this->logFailedQuery($event);
        }
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
            'Slow query detected: %s ms | SQL: %s',
            round($timeMs, 2),
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
}

