<?php

namespace Flowlog\FlowlogLaravel\Jobs;

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
        $this->connection = config('flowlog.queue.connection');
        $this->tries = config('flowlog.queue.tries', 3);
        $this->backoff = config('flowlog.queue.backoff', [1, 5, 10]);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
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
                    'user_id' => auth()->id(),
                    'logs' => $this->logs,
                ]);

            if (! $response->successful()) {
                $this->handleFailure($response);
            }
        } catch (\Exception $e) {
            // Log the error but don't throw to avoid infinite retry loops
            Log::error('Flowlog: Failed to send logs', [
                'error' => $e->getMessage(),
                'logs_count' => count($this->logs),
            ]);

            throw $e; // Re-throw to trigger retry mechanism
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
            Log::warning('Flowlog: Client error when sending logs', [
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
        Log::error('Flowlog: Job failed after all retries', [
            'error' => $exception->getMessage(),
            'logs_count' => count($this->logs),
        ]);
    }
}

