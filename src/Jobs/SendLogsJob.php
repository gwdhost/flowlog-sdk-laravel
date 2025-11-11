<?php

namespace Flowlog\FlowlogLaravel\Jobs;

use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendLogsJob implements ShouldQueue
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
     * Create a new job instance.
     */
    public function __construct(
        public array $logs,
        public string $apiUrl,
        public string $apiKey
    ) {
        $this->queue = config('flowlog.queue.queue', 'default');
        $this->connection = config('flowlog.queue.connection', 'sync');
        $this->tries = config('flowlog.queue.tries', 3);
        $this->backoff = config('flowlog.queue.backoff', [1, 5, 10]);
        
        if ($this->connection != 'sync') {
            // Get debounce delay from config (default: 3 seconds)
            $debounceDelay = config('flowlog.queue.debounce_delay', 3);
            
            // Delay the job by the debounce delay
            $this->delay(now()->addSeconds($debounceDelay));
            
            $this->log('info', 'Flowlog: Job delayed', [
                'debounce_delay' => $debounceDelay,
                'logs_count' => count($this->logs),
            ]);
        } else {
            $this->log('info', 'Flowlog: Job started', [
                'logs_count' => count($this->logs),
            ]);
        }
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (empty($this->logs)) {
            return;
        }

        $this->log('info', 'Flowlog: Job handle started', [
            'logs_count' => count($this->logs),
        ]);

        // Use guard to prevent infinite loops
        FlowlogGuard::whileInSendLogsJob(function () {
            // Send logs in chunks to handle large batches
            $this->sendLogsInChunks($this->logs);

            $this->log('info', 'Flowlog: Job handle completed', [
                'logs_count' => count($this->logs),
            ]);
        });
    }

    /**
     * Send logs in chunks to handle large batches.
     */
    protected function sendLogsInChunks(array $logs): void
    {
        $chunkSize = config('flowlog.chunk_size', 100);
        $chunks = array_chunk($logs, $chunkSize);

        foreach ($chunks as $chunkIndex => $chunk) {
            try {
                $this->sendChunk($chunk, $chunkIndex + 1, count($chunks));
            } catch (\Exception $e) {                
                $this->log('error', 'Flowlog: Failed to send chunk', [
                    'error' => $e->getMessage(),
                    'chunk' => $chunkIndex + 1,
                    'total_chunks' => count($chunks),
                    'chunk_size' => count($chunk),
                ]);

                // Re-throw to trigger retry mechanism for this chunk
                throw $e;
            }
        }
    }

    /**
     * Send a single chunk of logs to the API.
     */
    protected function sendChunk(array $chunk, int $chunkNumber, int $totalChunks): void
    {
        $this->log('warning', 'Flowlog: Sending chunk', [
            'chunk_number' => $chunkNumber,
            'total_chunks' => $totalChunks,
            'chunk_size' => count($chunk),
        ]);
        // Use whileSending guard to prevent HTTP logging during API requests
        // Also set shouldIgnore to prevent any logging during the HTTP request
        FlowlogGuard::whileSending(function () use ($chunk, $chunkNumber, $totalChunks) {
            $previousIgnoreState = FlowlogGuard::shouldIgnore();
            FlowlogGuard::setIgnore(true);
            
            try {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Authorization' => "Bearer {$this->apiKey}",
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                        'X-Flowlog-Ignore' => '1', // Set header to prevent logging on API side
                    ])
                    ->post($this->apiUrl ?? config('flowlog.api_url'), [
                        'service' => config('flowlog.service', 'laravel'),
                        'env' => config('flowlog.env'),
                        'logs' => $chunk,
                    ]);

                if (! $response->successful()) {
                    $this->handleFailure($response);
                } else {
                    $this->log('warning', 'Flowlog: Chunk sent', [
                        'chunk_number' => $chunkNumber,
                        'total_chunks' => $totalChunks,
                        'chunk_size' => count($chunk),
                    ]);
                }
            } finally {
                // Reset the ignore state to its previous value
                FlowlogGuard::setIgnore($previousIgnoreState);
            }
        });
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
            $this->log('warning', 'Flowlog: Client error when sending logs', [
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
        $this->log('error', 'Flowlog: Job failed after all retries', [
            'error' => $exception->getMessage(),
            'logs_count' => count($this->logs),
        ]);
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $fallbackChannel = config('flowlog.fallback_log_channel', 'single');
        if ($fallbackChannel === 'flowlog') {
            $fallbackChannel = 'single'; // Force to 'single' to prevent loops
        }
        
        Log::channel($fallbackChannel)->{$level}($message, $context);
    }
}

