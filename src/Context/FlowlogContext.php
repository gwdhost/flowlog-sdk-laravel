<?php

namespace Flowlog\FlowlogLaravel\Context;

/**
 * Context service for storing request-scoped Flowlog context.
 * 
 * This service stores iteration keys and trace IDs that are accessible
 * throughout the request lifecycle, including database queries and queue jobs.
 */
class FlowlogContext
{
    /**
     * Current iteration key for the request/job context.
     */
    protected static ?string $iterationKey = null;

    /**
     * Current trace ID for the request/job context.
     */
    protected static ?string $traceId = null;

    /**
     * Set the iteration key for the current context.
     */
    public static function setIterationKey(?string $iterationKey): void
    {
        self::$iterationKey = $iterationKey;
    }

    /**
     * Get the current iteration key.
     */
    public static function getIterationKey(): ?string
    {
        return self::$iterationKey;
    }

    /**
     * Set the trace ID for the current context.
     */
    public static function setTraceId(?string $traceId): void
    {
        self::$traceId = $traceId;
    }

    /**
     * Get the current trace ID.
     */
    public static function getTraceId(): ?string
    {
        return self::$traceId;
    }

    /**
     * Clear all context (iteration key and trace ID).
     */
    public static function clear(): void
    {
        self::$iterationKey = null;
        self::$traceId = null;
    }
}

