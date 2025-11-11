<?php

namespace Flowlog\FlowlogLaravel;

use Illuminate\Support\Facades\Log;

class Flowlog
{
    protected ?string $iterationKey = null;
    protected ?string $traceId = null;
    protected array $context = [];

    /**
     * Set the iteration key for grouping related logs.
     */
    public function setIterationKey(string $iterationKey): self
    {
        $this->iterationKey = $iterationKey;

        return $this;
    }

    /**
     * Set the trace ID for request tracing.
     */
    public function setTraceId(string $traceId): self
    {
        $this->traceId = $traceId;

        return $this;
    }

    /**
     * Add context that will be included in all subsequent logs.
     */
    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * Clear all context.
     */
    public function clearContext(): self
    {
        $this->context = [];
        $this->iterationKey = null;
        $this->traceId = null;

        return $this;
    }

    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): self
    {
        return $this->log('info', $message, $context);
    }

    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): self
    {
        return $this->log('error', $message, $context);
    }

    /**
     * Log a warning message.
     */
    public function warn(string $message, array $context = []): self
    {
        return $this->log('warning', $message, $context);
    }

    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): self
    {
        return $this->log('debug', $message, $context);
    }

    /**
     * Log a critical message.
     */
    public function critical(string $message, array $context = []): self
    {
        return $this->log('critical', $message, $context);
    }

    /**
     * Internal log method.
     */
    protected function log(string $level, string $message, array $context = []): self
    {
        $logContext = $this->context;

        // Add iteration key and trace ID if set
        if ($this->iterationKey) {
            $logContext['iteration_key'] = $this->iterationKey;
        }

        if ($this->traceId) {
            $logContext['trace_id'] = $this->traceId;
        }

        // Merge with provided context
        $logContext = array_merge($logContext, $context);

        Log::channel('flowlog')->{$level}($message, $logContext);

        return $this;
    }

    /**
     * Report an exception.
     */
    public function reportException(\Throwable $exception, array $context = []): void
    {
        $level = config('flowlog.exceptions.level', 'error');
        $logContext = array_merge($this->context, $context);

        $errorMessage = sprintf(
            '%s: %s in %s:%d',
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );

        Log::channel('flowlog')->{$level}(
            $exception->getMessage(),
            $logContext + ['error_message' => $errorMessage]
        );
    }
}

