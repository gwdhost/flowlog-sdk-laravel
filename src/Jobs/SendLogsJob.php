<?php

namespace Flowlog\FlowlogLaravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendLogsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff;

    /**
     * The unique ID of the job.
     */
    public string $uniqueId = 'flowlog-batch';

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 3600;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public array $logs,
        public string $apiUrl,
        public string $apiKey
    ) {
        $this->queue = config('flowlog.queue.queue', 'default');
        $this->connection = config('flowlog.queue.connection');
        $this->tries = config('flowlog.queue.tries', 3);
        $this->backoff = config('flowlog.queue.backoff', [1, 5, 10]);
        
        // Get debounce delay from config (default: 3 seconds)
        $debounceDelay = config('flowlog.queue.debounce_delay', 3);
        
        // Delay the job by the debounce delay
        $this->delay(now()->addSeconds($debounceDelay));
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return $this->uniqueId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cacheKey = $this->getCacheKey();
        
        // Use lock to atomically read and clear cache (prevents race conditions)
        // This works across Laravel 10, 11, and 12
        $lock = Cache::lock($cacheKey . ':lock', 10);
        
        $allLogs = $lock->get(function () use ($cacheKey) {
            // Get all accumulated logs from cache
            $accumulatedLogs = Cache::get($cacheKey, []);
            
            // Merge with current logs (from this job instance)
            $allLogs = array_merge($accumulatedLogs, $this->logs);
            
            // Clear the cache atomically
            Cache::forget($cacheKey);
            
            return $allLogs;
        });
        
        // If lock failed or no logs, return early
        // Fallback: if lock couldn't be acquired, just use current logs
        if ($allLogs === null || empty($allLogs)) {
            if (!empty($this->logs)) {
                $allLogs = $this->logs;
            } else {
                return;
            }
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])
                ->post($this->apiUrl ?? config('flowlog.api_url'), [
                    'service' => config('flowlog.service', 'laravel'),
                    'env' => config('flowlog.env'),
                    'logs' => $allLogs,
                ]);

            if (! $response->successful()) {
                $this->handleFailure($response);
            }
        } catch (\Exception $e) {
            // Log the error but don't throw to avoid infinite retry loops
            Log::channel(config('flowlog.fallback_log_channel'))
                ->error('Flowlog: Failed to send logs', [
                    'error' => $e->getMessage(),
                    'logs_count' => count($allLogs),
                ]);

            throw $e; // Re-throw to trigger retry mechanism
        }
    }

    /**
     * Get the cache key for storing accumulated logs.
     */
    protected function getCacheKey(): string
    {
        return 'flowlog:batched-logs:' . $this->uniqueId;
    }

    /**
     * Add logs to the cache before dispatching.
     * This is called statically before dispatching to accumulate logs.
     * This ensures logs are accumulated even if a unique job is already queued.
     * 
     * Works with Laravel 10, 11, and 12.
     */
    public static function accumulateLogs(array $logs, string $uniqueId = 'flowlog-batch'): void
    {
        if (empty($logs)) {
            return;
        }
        
        $cacheKey = "flowlog:batched-logs:{$uniqueId}";
        $debounceDelay = config('flowlog.queue.debounce_delay', 3);
        
        // Use atomic operation to merge logs (compatible with Laravel 10, 11, 12)
        $lock = Cache::lock($cacheKey . ':lock', 10);
        
        $result = $lock->get(function () use ($cacheKey, $logs, $debounceDelay) {
            // Get existing logs from cache
            $existingLogs = Cache::get($cacheKey, []);
            
            // Merge new logs with existing ones
            $allLogs = array_merge($existingLogs, $logs);
            
            // Store back in cache with TTL longer than debounce delay to ensure job can read it
            // Add extra buffer (30 seconds) to account for job processing time
            Cache::put($cacheKey, $allLogs, now()->addSeconds($debounceDelay + 30));
            
            return true;
        });
        
        // If lock couldn't be acquired, try without lock (fallback for compatibility)
        if ($result === null) {
            $existingLogs = Cache::get($cacheKey, []);
            $allLogs = array_merge($existingLogs, $logs);
            Cache::put($cacheKey, $allLogs, now()->addSeconds($debounceDelay + 30));
        }
    }

    /**
     * Handle failed response from API.
     */
    protected function handleFailure($response): void
    {
        $status = $response->status();
        $body = $response->body();

        // Don't retry on client errors (4xx) except 429 (rate limit)
        if ($status >= 400 && $status < 500 && $status !== 429) {
            Log::channel(config('flowlog.fallback_log_channel'))
                ->warning('Flowlog: Client error when sending logs', [
                    'status' => $status,
                    'body' => $body,
                ]);

            return;
        }

        // For server errors (5xx) and rate limits (429), throw to trigger retry
        throw new \Exception("Flowlog API returned status {$status}: {$body}");
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::channel(config('flowlog.fallback_log_channel'))
            ->error('Flowlog: Job failed after all retries', [
                'error' => $exception->getMessage(),
                'logs_count' => count($this->logs),
            ]);
    }
}

