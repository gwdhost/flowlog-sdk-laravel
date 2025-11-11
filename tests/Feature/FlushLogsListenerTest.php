<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Listeners\FlushLogsListener;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Logger;

it('flushes logs when job is processed', function () {
    Queue::fake();

    // Create a handler and manually add it to the log manager for testing
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    // Manually add logs to the handler (simulating what happens when logging)
    $handler->handle(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: \Monolog\Level::Info,
        message: 'Test log message'
    ));

    // Verify logs are accumulated
    $logs = $handler->getAllLogs();
    expect($logs)->toHaveCount(1);

    // Mock the log manager to return our handler
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $flowlogChannel = \Mockery::mock('Monolog\Logger');
    $flowlogChannel->shouldReceive('getHandlers')->andReturn([$handler]);
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    // Allow error() calls (for error logging in flushLogs)
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);

    // Simulate job completion event
    $listener = new FlushLogsListener();
    $jobProcessedEvent = new JobProcessed('test-connection', new \Illuminate\Queue\Jobs\SyncJob(
        app(),
        '{"uuid":"test","displayName":"App\\Jobs\\TestJob","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\TestJob","command":"O:15:\"App\\Jobs\\TestJob\":0:{}}"}',
        'test-queue',
        []
    ));
    
    $listener->handleJobProcessed($jobProcessedEvent);

    // Should have dispatched SendLogsJob
    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class, function ($job) {
        return count($job->logs) === 1
            && $job->apiUrl === config('flowlog.api_url')
            && $job->apiKey === config('flowlog.api_key');
    });

    // Batch should be cleared
    expect($handler->getAllLogs())->toHaveCount(0);
});

it('flushes logs when job fails', function () {
    Queue::fake();

    // Create a handler and manually add logs
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    $handler->handle(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: \Monolog\Level::Error,
        message: 'Error log message'
    ));

    // Mock the log manager to return our handler
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $flowlogChannel = \Mockery::mock('Monolog\Logger');
    $flowlogChannel->shouldReceive('getHandlers')->andReturn([$handler]);
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    // Allow error() calls (for error logging in flushLogs)
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);

    $listener = new FlushLogsListener();
    $jobFailedEvent = new \Illuminate\Queue\Events\JobFailed(
        'test-connection',
        new \Illuminate\Queue\Jobs\SyncJob(
            app(),
            '{"uuid":"test","displayName":"App\\Jobs\\TestJob","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\TestJob","command":"O:15:\"App\\Jobs\\TestJob\":0:{}}"}',
            'test-queue',
            []
        ),
        new \Exception('Job failed')
    );
    
    $listener->handleJobFailed($jobFailedEvent);

    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class);
    expect($handler->getAllLogs())->toHaveCount(0);
});

