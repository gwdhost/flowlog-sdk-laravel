<?php

namespace Flowlog\FlowlogLaravel\Traits;

/**
 * Trait to mark jobs that should ignore all Flowlog logging during execution.
 * 
 * When a job uses this trait, all logs (including HTTP, query, and direct log calls)
 * will be ignored during the job's execution.
 * 
 * Usage:
 * ```php
 * use Flowlog\FlowlogLaravel\Traits\FlowlogIgnoreTrait;
 * 
 * class MyJob implements ShouldQueue
 * {
 *     use FlowlogIgnoreTrait;
 *     
 *     public function handle()
 *     {
 *         // All logs during this job execution will be ignored
 *     }
 * }
 * ```
 */
trait FlowlogIgnoreTrait
{
    // Marker trait - no methods needed
    // The logic is handled by JobListener
}

