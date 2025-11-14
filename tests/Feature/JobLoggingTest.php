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

    // Create a handler and manually add it to the log manager for testing
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    // Mock the log manager to return our handler
    // Clear any resolved instance first
    app()->forgetInstance('log');
    
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $flowlogChannel = \Mockery::mock('Monolog\Logger');
    $singleChannel = \Mockery::mock('Monolog\Logger');
    
    // Allow getHandlers() to be called multiple times
    $flowlogChannel->shouldReceive('getHandlers')->andReturn([$handler]);
    // Allow info() to be called and pass through to the handler
    $flowlogChannel->shouldReceive('info')->andReturnUsing(function ($message, $context = []) use ($handler) {
        $handler->handle(new \Monolog\LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'flowlog',
            level: \Monolog\Level::Info,
            message: $message,
            context: $context
        ));
    });
    // Allow channel() to be called multiple times with different channels
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    // SendLogsJob constructor calls log() which uses 'single' channel as fallback
    $logManager->shouldReceive('channel')->with('single')->andReturn($singleChannel);
    $singleChannel->shouldReceive('info')->andReturn(true);
    // Allow error() calls (for error logging in flushLogs)
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);

    // Simulate a job that logs something
    Log::channel('flowlog')->info('Log from job execution');

    // Verify logs are accumulated
    $logs = $handler->getAllLogs();
    expect($logs)->toHaveCount(1);

    // Simulate job completion - this should trigger FlushLogsListener
    $listener = app(FlushLogsListener::class);
    
    // Create a mock job that is NOT SendLogsJob
    $mockJob = \Mockery::mock(\Illuminate\Contracts\Queue\Job::class);
    // resolveName() might throw, so allow it to return the job class name
    $mockJob->shouldReceive('resolveName')->andReturn('App\Jobs\TestJob');
    // Also allow payload() as fallback
    $mockJob->shouldReceive('payload')->andReturn([
        'uuid' => 'test',
        'displayName' => 'App\Jobs\TestJob',
        'job' => 'Illuminate\Queue\CallQueuedHandler@call',
    ]);
    // Make sure the job property is accessible
    $mockJob->shouldReceive('getJobId')->andReturn('test-job-id');
    
    $event = new JobProcessed('sync', $mockJob);
    $listener->handleJobProcessed($event);

    // Should have dispatched SendLogsJob
    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class, function ($job) {
        return count($job->logs) === 1;
    });

    // Batch should be cleared
    expect($handler->getAllLogs())->toHaveCount(0);
});

