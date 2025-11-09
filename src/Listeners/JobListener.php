<?php

namespace Flowlog\FlowlogLaravel\Listeners;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;

class JobListener
{
    protected ContextExtractor $contextExtractor;
    protected array $jobStartTimes = [];

    public function __construct()
    {
        $this->contextExtractor = new ContextExtractor();
    }

    /**
     * Handle job processing event.
     */
    public function handleProcessing(JobProcessing $event): void
    {
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job)) {
            return;
        }

        $this->jobStartTimes[$event->job->getJobId()] = microtime(true);

        $context = $this->contextExtractor->extractJobContext(
            get_class($event->job),
            $event->job->getQueue(),
            $event->job->attempts()
        );

        $message = sprintf(
            'Job processing: %s on queue %s (attempt %d)',
            $this->getJobName($event->job),
            $event->job->getQueue(),
            $event->job->attempts()
        );

        Log::channel('flowlog')->info($message, $context);
    }

    /**
     * Handle job processed event.
     */
    public function handleProcessed(JobProcessed $event): void
    {
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job)) {
            return;
        }

        $jobId = $event->job->getJobId();
        $startTime = $this->jobStartTimes[$jobId] ?? null;
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        unset($this->jobStartTimes[$jobId]);

        $context = $this->contextExtractor->extractJobContext(
            get_class($event->job),
            $event->job->getQueue(),
            $event->job->attempts(),
            $executionTime
        );

        $message = sprintf(
            'Job completed: %s on queue %s',
            $this->getJobName($event->job),
            $event->job->getQueue()
        );

        Log::channel('flowlog')->info($message, $context);
    }

    /**
     * Handle job failed event.
     */
    public function handleFailed(JobFailed $event): void
    {
        // Check if job should be excluded
        if ($this->shouldExcludeJob($event->job)) {
            return;
        }

        $jobId = $event->job->getJobId();
        $startTime = $this->jobStartTimes[$jobId] ?? null;
        $executionTime = $startTime ? microtime(true) - $startTime : null;

        unset($this->jobStartTimes[$jobId]);

        $context = $this->contextExtractor->extractJobContext(
            get_class($event->job),
            $event->job->getQueue(),
            $event->job->attempts(),
            $executionTime
        );

        // Add exception context
        if ($event->exception) {
            $exceptionContext = $this->contextExtractor->extractExceptionContext($event->exception);
            $context = array_merge($context, $exceptionContext);
        }

        $message = sprintf(
            'Job failed: %s on queue %s (attempt %d)',
            $this->getJobName($event->job),
            $event->job->getQueue(),
            $event->job->attempts()
        );

        Log::channel('flowlog')->error($message, $context);
    }

    /**
     * Check if job should be excluded from logging.
     */
    protected function shouldExcludeJob($job): bool
    {
        if ($job instanceof SendLogsJob) {
            return true;
        }

        if (strpos(get_class($job), 'Illuminate\\Queue\\Jobs') !== false) {
            return true;
        }
        if (strpos(get_class($job), 'Laravel\\Scout\\Jobs') !== false) {
            return true;
        }

        $excludeJobs = config('flowlog.jobs.exclude_jobs', []);
        $jobClass = get_class($job);

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
    protected function getJobName($job): string
    {
        if (method_exists($job, 'displayName')) {
            return $job->displayName();
        }

        $jobClass = get_class($job);

        // Extract class name without namespace
        $parts = explode('\\', $jobClass);

        return end($parts);
    }
}

