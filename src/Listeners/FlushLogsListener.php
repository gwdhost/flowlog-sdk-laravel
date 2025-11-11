<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;

class FlushLogsListener
{
    /**
     * Handle the RequestHandled event.
     * Flush logs at the end of request lifecycle (for console/artisan commands).
     */
    public function handle(RequestHandled $event): void
    {
        // Only flush for console/artisan commands (HTTP requests are handled by terminating middleware)
        if (!app()->runningInConsole()) {
            return;
        }

        // Don't flush when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        // Use guard to prevent infinite loops during flush
        FlowlogGuard::whileSending(function () {
            try {
                // Get the FlowlogHandler instance from the log channel
                $logManager = app('log');
                $flowlogChannel = $logManager->channel('flowlog');
                
                if ($flowlogChannel) {
                    $handlers = $flowlogChannel->getHandlers();
                    
                    foreach ($handlers as $handler) {
                        if ($handler instanceof FlowlogHandler) {
                            $logs = $handler->getAllLogs();
                            
                            if (!empty($logs)) {
                                // Dispatch job to send logs (will be chunked if needed)
                                SendLogsJob::dispatch(
                                    $logs,
                                    config('flowlog.api_url'),
                                    config('flowlog.api_key')
                                );
                                
                                // Clear the batch after dispatching
                                $handler->clearBatch();
                            }
                            
                            // Only process the first FlowlogHandler instance
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail to prevent breaking the command lifecycle
                // Log to default logger if available
                if (app()->bound('log')) {
                    \Illuminate\Support\Facades\Log::error('Flowlog: Failed to flush logs in RequestHandled listener', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Handle the JobProcessed event.
     * Flush logs after job completion (for queue workers).
     */
    public function handleJobProcessed(JobProcessed $event): void
    {
        // CRITICAL: Don't flush when SendLogsJob completes - this would create an infinite loop
        // SendLogsJob is responsible for sending logs, not accumulating them
        if ($this->isSendLogsJob($event)) {
            return;
        }

        // Don't flush when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        $this->flushLogs();
    }

    /**
     * Handle the JobFailed event.
     * Flush logs after job failure (for queue workers).
     */
    public function handleJobFailed(JobFailed $event): void
    {
        // CRITICAL: Don't flush when SendLogsJob fails - this would create an infinite loop
        if ($this->isSendLogsJob($event)) {
            return;
        }

        // Don't flush when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        $this->flushLogs();
    }

    /**
     * Check if the job event is for SendLogsJob.
     */
    protected function isSendLogsJob($event): bool
    {
        $job = $event->job;
        
        // Get the job class name using the same method as JobListener
        $jobClass = $this->getJobClass($job);
        
        // Check if it's SendLogsJob
        return $jobClass === SendLogsJob::class || 
               str_contains($jobClass, 'SendLogsJob');
    }

    /**
     * Check if the job event is for ProcessLogJob.
     * ProcessLogJob processes incoming logs from the API and shouldn't trigger flushing.
     */
    protected function isProcessLogJob($event): bool
    {
        $job = $event->job;
        
        // Get the job class name using the same method as JobListener
        $jobClass = $this->getJobClass($job);
        
        // Check if it's ProcessLogJob (from flowlog-api)
        return str_contains($jobClass, 'ProcessLogJob') ||
               $jobClass === 'App\Jobs\ProcessLogJob';
    }

    /**
     * Get the actual job class name from the queue job instance.
     * Uses the same method as JobListener for consistency.
     */
    protected function getJobClass($queueJob): string
    {
        // Try resolveName() first (Laravel 5.5+)
        if (method_exists($queueJob, 'resolveName')) {
            try {
                return $queueJob->resolveName();
            } catch (\Throwable $e) {
                // Ignore errors, continue to fallback
            }
        }

        // Fallback: try to get from payload
        if (method_exists($queueJob, 'payload')) {
            try {
                $payload = $queueJob->payload();
                if (isset($payload['displayName'])) {
                    return $payload['displayName'];
                }
                if (isset($payload['job'])) {
                    return $payload['job'];
                }
            } catch (\Throwable $e) {
                // Ignore errors, continue to fallback
            }
        }

        // Last resort: use the queue job class itself
        return get_class($queueJob);
    }

    /**
     * Flush accumulated logs.
     */
    protected function flushLogs(): void
    {
        // Don't flush when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        // Use guard to prevent infinite loops during flush
        FlowlogGuard::whileSending(function () {
            try {
                // Get the FlowlogHandler instance from the log channel
                $logManager = app('log');
                $flowlogChannel = $logManager->channel('flowlog');
                
                if ($flowlogChannel) {
                    $handlers = $flowlogChannel->getHandlers();
                    
                    foreach ($handlers as $handler) {
                        if ($handler instanceof FlowlogHandler) {
                            $logs = $handler->getAllLogs();
                            
                            if (!empty($logs)) {
                                // Dispatch job to send logs (will be chunked if needed)
                                SendLogsJob::dispatch(
                                    $logs,
                                    config('flowlog.api_url'),
                                    config('flowlog.api_key')
                                );
                                
                                // Clear the batch after dispatching
                                $handler->clearBatch();
                            }
                            
                            // Only process the first FlowlogHandler instance
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail to prevent breaking the job lifecycle
                // Log to default logger if available
                if (app()->bound('log')) {
                    \Illuminate\Support\Facades\Log::error('Flowlog: Failed to flush logs', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}

