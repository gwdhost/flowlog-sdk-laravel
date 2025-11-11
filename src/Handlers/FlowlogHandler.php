<?php

namespace Flowlog\FlowlogLaravel\Handlers;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Illuminate\Support\Arr;
use Monolog\Handler\AbstractHandler;
use Monolog\LogRecord;

class FlowlogHandler extends AbstractHandler
{
    protected array $batch = [];
    protected ?int $lastFlushTime = null;
    protected ContextExtractor $contextExtractor;

    public function __construct(
        protected string $apiUrl,
        protected string $apiKey,
        protected string $service,
        protected string $env,
        protected int $batchSize = 50,
        protected int $batchInterval = 5,
        protected int $maxBatchSizeBytes = 64 * 1024
    ) {
        parent::__construct();
        $this->contextExtractor = new ContextExtractor();
        $this->lastFlushTime = time();
    }

    /**
     * Handle a log record.
     */
    public function handle(LogRecord $record): bool
    {
        if (! $this->isHandling($record)) {
            return false;
        }

        $logEntry = $this->formatLogEntry($record);
        $this->batch[] = $logEntry;

        // Check if we should flush
        if ($this->shouldFlush()) {
            $this->flush();
        }

        return true;
    }

    /**
     * Format a log record into Flowlog format.
     */
    protected function formatLogEntry(LogRecord $record): array
    {
        $context = $this->contextExtractor->extract();

        // Merge any additional context from the log record
        if (! empty($record->context)) {
            $context = array_merge($context, $record->context);
        }

        // Extract iteration key and trace ID from context if present
        $iterationKey = !empty($context['iteration_key']) ? (string) $context['iteration_key'] : null;
        $traceId = !empty($context['trace_id']) ? (string) $context['trace_id'] : null;
        $sessionId = !empty($context['session_id']) ? (string) $context['session_id'] : null;
        $userId = !empty($context['user_id']) ? (string) $context['user_id'] : null;

        // Format the message
        $message = $record->message;
        if (empty($message)) {
            $message = $this->formatMessage($record->message, $record->context);
        }

        // Ensure timestamp is in UTC
        $timestamp = $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('c');

        $logEntry = [
            'user_id' => $userId,
            'session_id' => $sessionId,
            'level' => $this->mapLogLevel($record->level->getName()),
            'timestamp' => $timestamp,
            'trace_id' => $traceId,
            'payload' => json_encode([
                'message' => $message,
            ] + Arr::except(
                $context,
                ['iteration_key', 'trace_id', 'session_id', 'user_id']
            )),
        ];

        // Only include iteration_key if it's not null (API validation requirement)
        if ($iterationKey !== null) {
            $logEntry['iteration_key'] = $iterationKey;
        }

        return $logEntry;
    }

    /**
     * Format message with context data.
     */
    protected function formatMessage(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        // If context is simple, append as JSON
        $contextStr = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "{$message} | Context: {$contextStr}";
    }

    /**
     * Map Monolog log levels to Flowlog levels.
     */
    protected function mapLogLevel(string $level): string
    {
        return match (strtolower($level)) {
            'debug' => 'debug',
            'info' => 'info',
            'notice' => 'info',
            'warning' => 'warning',
            'error' => 'error',
            'critical' => 'critical',
            'alert' => 'critical',
            'emergency' => 'critical',
            default => 'info',
        };
    }

    /**
     * Check if we should flush the batch.
     */
    protected function shouldFlush(): bool
    {
        // Flush if batch size reached
        if (count($this->batch) >= $this->batchSize) {
            return true;
        }

        // Flush if batch size in bytes exceeded
        $batchSizeBytes = strlen(json_encode($this->batch));
        if ($batchSizeBytes >= $this->maxBatchSizeBytes) {
            return true;
        }

        // Flush if interval elapsed
        if ((time() - $this->lastFlushTime) >= $this->batchInterval) {
            return true;
        }

        return false;
    }

    /**
     * Flush the batch to the queue.
     */
    protected function flush(): void
    {
        if (empty($this->batch)) {
            return;
        }

        $logs = $this->batch;
        $this->batch = [];
        $this->lastFlushTime = time();

        try {
            // Accumulate logs in cache first
            SendLogsJob::accumulateLogs($logs);
            
            // Dispatch the job (it will be unique and delayed, merging with cache on execution)
            SendLogsJob::dispatch($logs, $this->apiUrl, $this->apiKey);
        } catch (\Exception $e) {
            // If queue fails, try to log to Laravel's default logger
            // This prevents losing logs if queue is down
            \Illuminate\Support\Facades\Log::error('Flowlog: Failed to dispatch log job', [
                'error' => $e->getMessage(),
                'logs_count' => count($logs),
            ]);
        }
    }

    /**
     * Flush any remaining logs (called on shutdown).
     */
    public function __destruct()
    {
        $this->flush();
    }
}

