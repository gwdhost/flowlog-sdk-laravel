<?php

namespace Flowlog\FlowlogLaravel\Guards;

/**
 * Guard class to prevent infinite loops in Flowlog logging.
 * 
 * This class provides static flags to track when Flowlog operations
 * are in progress, preventing recursive logging.
 */
class FlowlogGuard
{
    /**
     * Track if Flowlog is currently processing logs.
     * This prevents query/job/HTTP listeners from logging during Flowlog operations.
     */
    protected static bool $isProcessing = false;

    /**
     * Track if we're inside a SendLogsJob execution.
     */
    protected static bool $inSendLogsJob = false;

    /**
     * Track if we're inside FlowlogHandler flush operation.
     */
    protected static bool $inFlush = false;

    /**
     * Track if we're sending logs to the API (HTTP requests).
     */
    protected static bool $isSending = false;

    /**
     * Track if we're performing cache operations.
     */
    protected static bool $isCaching = false;

    /**
     * Track if we're inside ProcessLogJob execution.
     */
    protected static bool $inProcessLogJob = false;

    /**
     * Track if logging should be ignored (set via X-Flowlog-Ignore header or programmatically).
     */
    protected static bool $shouldIgnore = false;


    /**
     * Check if Flowlog is currently processing (any operation).
     * 
     * @deprecated Use specific guard methods instead
     */
    public static function isProcessing(): bool
    {
        return self::$isProcessing || self::$inSendLogsJob || self::$inFlush || self::$isSending || self::$isCaching || self::$inProcessLogJob;
    }

    /**
     * Check if we're currently sending logs to the API.
     */
    public static function isSending(): bool
    {
        return self::$isSending;
    }

    /**
     * Check if we're currently performing cache operations.
     */
    public static function isCaching(): bool
    {
        return self::$isCaching;
    }

    /**
     * Check if we're inside SendLogsJob.
     */
    public static function inSendLogsJob(): bool
    {
        return self::$inSendLogsJob;
    }

    /**
     * Check if we're inside a flush operation.
     */
    public static function inFlush(): bool
    {
        return self::$inFlush;
    }

    /**
     * Execute a callback with processing guard enabled.
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileProcessing(callable $callback)
    {
        $previous = self::$isProcessing;
        self::$isProcessing = true;
        
        try {
            return $callback();
        } finally {
            self::$isProcessing = $previous;
        }
    }

    /**
     * Execute a callback with SendLogsJob guard enabled.
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileInSendLogsJob(callable $callback)
    {
        $previous = self::$inSendLogsJob;
        self::$inSendLogsJob = true;
        
        $result = null;

        try {
            $result = $callback();
        } finally {
            self::$inSendLogsJob = $previous;
        }

        return $result;
    }

    /**
     * Execute a callback with flush guard enabled.
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileFlushing(callable $callback)
    {
        $previous = self::$inFlush;
        self::$inFlush = true;
        
        $result = null;
        
        try {
            $result = $callback();
        } finally {
            self::$inFlush = $previous;
        }

        return $result;
    }

    /**
     * Execute a callback with sending guard enabled (for HTTP API requests).
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileSending(callable $callback)
    {
        $previous = self::$isSending;
        self::$isSending = true;
        
        $result = null;
        
        try {
            $result = $callback();
        } finally {
            self::$isSending = $previous;
        }

        return $result;
    }

    /**
     * Execute a callback with caching guard enabled (for cache operations).
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileCaching(callable $callback)
    {
        $previous = self::$isCaching;
        self::$isCaching = true;
        
        $result = null;
        
        try {
            $result = $callback();
        } finally {
            self::$isCaching = $previous;
        }

        return $result;
    }

    /**
     * Check if we're inside ProcessLogJob.
     */
    public static function inProcessLogJob(): bool
    {
        return self::$inProcessLogJob;
    }

    /**
     * Check if logging should be ignored.
     * This is set via X-Flowlog-Ignore header or programmatically.
     */
    public static function shouldIgnore(): bool
    {
        return self::$shouldIgnore;
    }

    /**
     * Set the ignore flag to prevent logging.
     * 
     * @param bool $ignore
     */
    public static function setIgnore(bool $ignore): void
    {
        self::$shouldIgnore = $ignore;
    }

    /**
     * Execute a callback with ProcessLogJob guard enabled.
     * 
     * @param callable $callback
     * @return mixed
     */
    public static function whileInProcessLogJob(callable $callback)
    {
        $previous = self::$inProcessLogJob;
        self::$inProcessLogJob = true;
        
        $result = null;
        
        try {
            $result = $callback();
        } finally {
            self::$inProcessLogJob = $previous;
        }

        return $result;
    }

    /**
     * Check if Flowlog is locked (any guard is active).
     */
    public static function isLocked(): bool
    {
        return self::$isProcessing || self::$inSendLogsJob || self::$inFlush || self::$isSending || self::$isCaching || self::$inProcessLogJob;
    }

    /**
     * Check if a SQL query is related to Flowlog operations.
     * 
     * @param string $sql
     * @return bool
     */
    public static function isFlowlogQuery(string $sql): bool
    {
        $sql = strtolower($sql);
        
        // Check for cache-related queries (if using database cache driver)
        // These are queries that SendLogsJob uses for caching logs
        $cachePatterns = [
            'cache', // Cache table queries
        ];
        
        foreach ($cachePatterns as $pattern) {
            if (str_contains($sql, $pattern)) {
                // Only exclude cache queries during SendLogsJob execution
                // (when we're actually sending logs, not during normal operations)
                if (self::inSendLogsJob()) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Check if a route path is a Flowlog API endpoint.
     * 
     * @deprecated Use shouldIgnore() instead. This method is kept for backward compatibility.
     * @param string $path
     * @return bool
     */
    public static function isFlowlogRoute(string $path): bool
    {
        $apiUrl = config('flowlog.api_url', '');
        if (empty($apiUrl)) {
            return false;
        }
        
        // Extract path from API URL
        $parsed = parse_url($apiUrl);
        $apiPath = $parsed['path'] ?? '';
        
        // Check if the request path matches the API path
        return str_starts_with($path, $apiPath) || 
               str_contains($path, '/api/v1/logs') ||
               str_contains($path, '/api/logs');
    }
}

