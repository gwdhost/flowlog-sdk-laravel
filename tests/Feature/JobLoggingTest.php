<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Listeners\FlushLogsListener;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Logger;

it('flushes logs accumulated during job execution', function () {
    Queue::fake();

    // Get the handler from the registered flowlog channel
    $logManager = app('log');
    $flowlogChannel = $logManager->channel('flowlog');
    
    expect($flowlogChannel)->not->toBeNull();
    
    $handlers = $flowlogChannel->getHandlers();
    $handler = null;
    foreach ($handlers as $h) {
        if ($h instanceof FlowlogHandler) {
            $handler = $h;
            break;
        }
    }

    expect($handler)->not->toBeNull();

    // Simulate a job that logs something
    Log::channel('flowlog')->info('Log from job execution');

    // Verify logs are accumulated
    $logs = $handler->getAllLogs();
    expect($logs)->toHaveCount(1);

    // Simulate job completion - this should trigger FlushLogsListener
    $listener = app(FlushLogsListener::class);
    
    // Create a mock job event
    $job = new class {
        public function getJobId() { return 'test-job-1'; }
        public function getConnectionName() { return 'sync'; }
        public function getQueue() { return 'default'; }
    };
    
    $event = new JobProcessed('sync', $job);
    $listener->handleJobProcessed($event);

    // Should have dispatched SendLogsJob
    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class, function ($job) {
        return count($job->logs) === 1;
    });

    // Batch should be cleared
    expect($handler->getAllLogs())->toHaveCount(0);
});

