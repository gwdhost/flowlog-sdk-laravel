<?php

namespace Flowlog\FlowlogLaravel\Traits;

use Flowlog\FlowlogLaravel\Context\FlowlogContext;

/**
 * Trait for jobs to automatically inherit iteration key from the current context.
 * 
 * When a job uses this trait, it will automatically get the iteration key
 * from FlowlogContext when dispatched. The JobListener will set it in FlowlogContext
 * when the job executes, making it available for queries and other logs.
 */
trait HasIterationKey
{
    /**
     * The iteration key for this job.
     * This will be automatically set from FlowlogContext when the job is dispatched.
     */
    public ?string $iterationKey = null;

    /**
     * The trace ID for this job.
     * This will be automatically set from FlowlogContext when the job is dispatched.
     */
    public ?string $traceId = null;

    /**
     * Initialize the trait - automatically attach iteration key when job is instantiated.
     * This method should be called from the job's constructor.
     */
    protected function initializeHasIterationKey(): void
    {
        // Automatically get iteration key and trace ID from current context
        $this->iterationKey = FlowlogContext::getIterationKey();
        $this->traceId = FlowlogContext::getTraceId();
    }
}

