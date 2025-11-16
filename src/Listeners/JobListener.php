<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Flowlog\FlowlogLaravel\Context\FlowlogContext;
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Flowlog\FlowlogLaravel\Traits\FlowlogIgnoreTrait;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class JobListener
{
    protected ContextExtractor $contextExtractor;
    protected array $jobStartTimes = [];
    protected array $jobIgnoreStates = [];

    public function __construct()
    {
        $this->contextExtractor = new ContextExtractor();
    }

    /**
     * Handle job processing event.
     */
    public function handleProcessing(JobProcessing $event): void
    {
        // Set the current job class for QueryListener to detect ProcessLogJob
        $jobClass = $this->getJobClass($event->job);
        \Flowlog\FlowlogLaravel\Listeners\QueryListener::setCurrentJobClass($jobClass);
        
        // Extract iteration key from job if it has one, and set it in FlowlogContext
        // This allows jobs to inherit the iteration key from the request that dispatched them
        $iterationKey = $this->getJobIterationKey($event->job);
        if ($iterationKey !== null) {
            FlowlogContext::setIterationKey($iterationKey);
        }
        
        // Extract trace ID from job if it has one
        $traceId = $this->getJobTraceId($event->job);
        if ($traceId !== null) {
            FlowlogContext::setTraceId($traceId);
        }
        
        // Check if job uses FlowlogIgnoreTrait and set ignore state
        $jobId = $this->getJobId($event->job);
        if ($this->jobUsesIgnoreTrait($jobClass)) {
            $this->jobIgnoreStates[$jobId] = FlowlogGuard::shouldIgnore();
            FlowlogGuard::setIgnore(true);
        }
        
        // Block all job logging during sending operations to prevent infinite loops
        if (FlowlogGuard::isSending()) {
            return;
        }

        // Prevent infinite loops: only block when actually sending logs to API
        // (not during flush/dispatch, which just queues a job)
        if (FlowlogGuard::inSendLogsJob()) {
            return;
        }
        
        // Don't log jobs when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }
        
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job, $jobClass)) {
            return;
        }

        $queue = $this->getQueue($event->job);
        $attempts = $this->getAttempts($event->job);

        $this->jobStartTimes[$jobId] = microtime(true);

        $context = $this->contextExtractor->extractJobContext(
            $jobClass,
            $queue,
            $attempts
        );

        $message = sprintf(
            'Job processing: %s on queue %s',
            $this->getJobName($event->job, $jobClass),
            $queue
        );

        Log::channel('flowlog')->info($message, $context);
    }

    /**
     * Handle job processed event.
     */
    public function handleProcessed(JobProcessed $event): void
    {
        // Clear the current job class when job completes
        \Flowlog\FlowlogLaravel\Listeners\QueryListener::setCurrentJobClass(null);
        
        $jobId = $this->getJobId($event->job);
        
        // Reset ignore state if job was using FlowlogIgnoreTrait
        if (isset($this->jobIgnoreStates[$jobId])) {
            FlowlogGuard::setIgnore($this->jobIgnoreStates[$jobId]);
            unset($this->jobIgnoreStates[$jobId]);
        }
        
        // Block all job logging during sending operations to prevent infinite loops
        if (FlowlogGuard::isSending()) {
            return;
        }

        // Prevent infinite loops: only block when actually sending logs to API
        if (FlowlogGuard::inSendLogsJob()) {
            return;
        }

        // Don't log jobs when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        $jobClass = $this->getJobClass($event->job);
        
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job, $jobClass)) {
            return;
        }

        $queue = $this->getQueue($event->job);
        $attempts = $this->getAttempts($event->job);
        
        $startTime = $this->jobStartTimes[$jobId] ?? null;
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        unset($this->jobStartTimes[$jobId]);

        $context = $this->contextExtractor->extractJobContext(
            $jobClass,
            $queue,
            $attempts,
            $executionTime
        );

        $message = sprintf(
            'Job completed: %s on queue %s',
            $this->getJobName($event->job, $jobClass),
            $queue
        );

        Log::channel('flowlog')->info($message, $context);
    }

    /**
     * Handle job failed event.
     */
    public function handleFailed(JobFailed $event): void
    {
        // Clear the current job class when job fails
        \Flowlog\FlowlogLaravel\Listeners\QueryListener::setCurrentJobClass(null);
        
        $jobId = $this->getJobId($event->job);
        
        // Reset ignore state if job was using FlowlogIgnoreTrait
        if (isset($this->jobIgnoreStates[$jobId])) {
            FlowlogGuard::setIgnore($this->jobIgnoreStates[$jobId]);
            unset($this->jobIgnoreStates[$jobId]);
        }
        
        // Block all job logging during sending operations to prevent infinite loops
        if (FlowlogGuard::isSending()) {
            return;
        }

        // Prevent infinite loops: only block when actually sending logs to API
        if (FlowlogGuard::inSendLogsJob()) {
            return;
        }

        // Don't log jobs when ignore guard is set (e.g., via X-Flowlog-Ignore header)
        if (FlowlogGuard::shouldIgnore()) {
            return;
        }

        $jobClass = $this->getJobClass($event->job);
        
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job, $jobClass)) {
            return;
        }

        $queue = $this->getQueue($event->job);
        $attempts = $this->getAttempts($event->job);
        
        $startTime = $this->jobStartTimes[$jobId] ?? null;
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        unset($this->jobStartTimes[$jobId]);

        $context = $this->contextExtractor->extractJobContext(
            $jobClass,
            $queue,
            $attempts,
            $executionTime
        );

        // Add exception context
        if ($event->exception) {
            $exceptionContext = $this->contextExtractor->extractExceptionContext($event->exception);
            $context = array_merge($context, $exceptionContext);
        }

        $message = sprintf(
            'Job failed: %s on queue %s',
            $this->getJobName($event->job, $jobClass),
            $queue
        );

        Log::channel('flowlog')->error($message, $context);
    }

    /**
     * Get the actual job class name from the queue job instance.
     */
    protected function getJobClass($queueJob): string
    {
        // Try resolveName() first (Laravel 5.5+)
        if (method_exists($queueJob, 'resolveName')) {
            return $queueJob->resolveName();
        }

        // Fallback: try to get from payload
        if (method_exists($queueJob, 'payload')) {
            $payload = $queueJob->payload();
            if (isset($payload['displayName'])) {
                return $payload['displayName'];
            }
            if (isset($payload['job'])) {
                return $payload['job'];
            }
        }

        // Last resort: use the queue job class itself
        return get_class($queueJob);
    }

    /**
     * Get job ID from queue job instance.
     */
    protected function getJobId($queueJob): string
    {
        if (method_exists($queueJob, 'getJobId')) {
            return $queueJob->getJobId();
        }

        // Fallback: use a combination of queue and timestamp
        return md5(
            $this->getQueue($queueJob) .
            (method_exists($queueJob, 'payload') ? serialize($queueJob->payload()) : '') .
            microtime(true)
        );
    }

    /**
     * Get queue name from queue job instance.
     */
    protected function getQueue($queueJob): string
    {
        if (method_exists($queueJob, 'getQueue')) {
            return $queueJob->getQueue();
        }

        // Fallback: try to get from connection
        if (method_exists($queueJob, 'getConnectionName')) {
            return $queueJob->getConnectionName() ?? 'default';
        }

        return 'default';
    }

    /**
     * Get attempt count from queue job instance.
     */
    protected function getAttempts($queueJob): int
    {
        if (method_exists($queueJob, 'attempts')) {
            return $queueJob->attempts();
        }

        // Fallback: try to get from payload
        if (method_exists($queueJob, 'payload')) {
            $payload = $queueJob->payload();
            return $payload['attempts'] ?? 1;
        }

        return 1;
    }

    /**
     * Check if job should be excluded from logging.
     */
    protected function shouldExcludeJob($queueJob, string $jobClass): bool
    {
        // Check if it's the SendLogsJob
        if ($jobClass === SendLogsJob::class || is_subclass_of($jobClass, SendLogsJob::class)) {
            return true;
        }

        // Exclude Laravel internal queue jobs
        if (Str::contains($jobClass, 'Illuminate\\Queue\\Jobs') !== false) {
            return true;
        }
        if (Str::contains($jobClass, 'Laravel\\Scout\\Jobs') !== false) {
            return true;
        }
        if (Str::contains($jobClass, 'Laravel\\Telescope\\Jobs') !== false) {
            return true;
        }

        // Check configured exclusions
        $excludeJobs = config('flowlog.jobs.exclude_jobs', []);

        foreach ($excludeJobs as $excludedClass) {
            if ($jobClass === $excludedClass || is_subclass_of($jobClass, $excludedClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get job name for logging.
     */
    protected function getJobName($queueJob, string $jobClass): string
    {
        // Try displayName() method
        if (method_exists($queueJob, 'displayName')) {
            return $queueJob->displayName();
        }

        // Extract class name without namespace
        $parts = explode('\\', $jobClass);
        return end($parts);
    }

    /**
     * Check if a job class uses the FlowlogIgnoreTrait.
     */
    protected function jobUsesIgnoreTrait(string $jobClass): bool
    {
        if (!class_exists($jobClass)) {
            return false;
        }

        $traits = class_uses_recursive($jobClass);
        return in_array(FlowlogIgnoreTrait::class, $traits, true);
    }

    /**
     * Get iteration key from job instance.
     * Checks for iterationKey property or method.
     */
    protected function getJobIterationKey($queueJob): ?string
    {
        // Try to get from job instance property
        if (is_object($queueJob) && property_exists($queueJob, 'iterationKey')) {
            return $queueJob->iterationKey ?? null;
        }

        // Try to get from job payload
        if (method_exists($queueJob, 'payload')) {
            $payload = $queueJob->payload();
            if (isset($payload['data']['iterationKey'])) {
                return $payload['data']['iterationKey'];
            }
        }

        // Try to get from job's command property (for serialized jobs)
        if (method_exists($queueJob, 'getCommand')) {
            $command = $queueJob->getCommand();
            if (is_object($command) && property_exists($command, 'iterationKey')) {
                return $command->iterationKey ?? null;
            }
        }

        return null;
    }

    /**
     * Get trace ID from job instance.
     * Checks for traceId property or method.
     */
    protected function getJobTraceId($queueJob): ?string
    {
        // Try to get from job instance property
        if (is_object($queueJob) && property_exists($queueJob, 'traceId')) {
            return $queueJob->traceId ?? null;
        }

        // Try to get from job payload
        if (method_exists($queueJob, 'payload')) {
            $payload = $queueJob->payload();
            if (isset($payload['data']['traceId'])) {
                return $payload['data']['traceId'];
            }
        }

        // Try to get from job's command property (for serialized jobs)
        if (method_exists($queueJob, 'getCommand')) {
            $command = $queueJob->getCommand();
            if (is_object($command) && property_exists($command, 'traceId')) {
                return $command->traceId ?? null;
            }
        }

        return null;
    }
}

