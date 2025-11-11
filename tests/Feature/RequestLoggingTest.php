<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

it('dispatches SendLogsJob exactly once after HTTP request and does not loop', function () {
    Queue::fake();

    // Create a handler and manually add logs (simulating what happens during a request)
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    // Simulate logging during a request
    $handler->handle(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: \Monolog\Level::Info,
        message: 'Test log from request'
    ));

    // Verify logs were accumulated during the request
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

    // Simulate the terminating middleware flushing logs (which happens after response is sent)
    // This should dispatch SendLogsJob exactly once
    $middleware = app(\Flowlog\FlowlogLaravel\Middleware\FlowlogTerminatingMiddleware::class);
    $middleware->terminate(
        request(),
        response()->json(['message' => 'ok'])
    );

    // Should have dispatched SendLogsJob exactly once
    Queue::assertPushed(SendLogsJob::class, 1);

    // Verify the job has the correct logs
    Queue::assertPushed(SendLogsJob::class, function ($job) {
        return count($job->logs) === 1
            && $job->apiUrl === config('flowlog.api_url')
            && $job->apiKey === config('flowlog.api_key');
    });

    // Batch should be cleared after flush
    expect($handler->getAllLogs())->toHaveCount(0);

    // Now simulate SendLogsJob completing - this should NOT trigger another dispatch
    // (This is the critical test to prevent infinite loops)
    $sendLogsJob = new SendLogsJob(
        logs: [['test' => 'data']],
        apiUrl: config('flowlog.api_url'),
        apiKey: config('flowlog.api_key')
    );

    // Create a job event for SendLogsJob completion
    $jobProcessedEvent = new JobProcessed(
        'sync',
        new \Illuminate\Queue\Jobs\SyncJob(
            app(),
            json_encode([
                'uuid' => 'test',
                'displayName' => SendLogsJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            ]),
            'default',
            []
        )
    );

    // Fire the event - this should NOT trigger another flush/dispatch
    $flushListener = app(\Flowlog\FlowlogLaravel\Listeners\FlushLogsListener::class);
    $flushListener->handleJobProcessed($jobProcessedEvent);

    // Verify no additional SendLogsJob was dispatched
    // (We should still have exactly 1 from the initial flush)
    Queue::assertPushed(SendLogsJob::class, 1);
});

it('does not dispatch SendLogsJob when SendLogsJob itself completes', function () {
    Queue::fake();

    // Simulate SendLogsJob completing
    $sendLogsJob = new SendLogsJob(
        logs: [['test' => 'data']],
        apiUrl: config('flowlog.api_url'),
        apiKey: config('flowlog.api_key')
    );

    // Create a job event for SendLogsJob completion
    $jobProcessedEvent = new JobProcessed(
        'sync',
        new \Illuminate\Queue\Jobs\SyncJob(
            app(),
            json_encode([
                'uuid' => 'test',
                'displayName' => SendLogsJob::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            ]),
            'default',
            []
        )
    );

    // Fire the event
    $flushListener = app(\Flowlog\FlowlogLaravel\Listeners\FlushLogsListener::class);
    $flushListener->handleJobProcessed($jobProcessedEvent);

    // Verify NO SendLogsJob was dispatched (SendLogsJob should not trigger flush)
    Queue::assertNothingPushed();
});

it('actually executes SendLogsJob and sends logs to API', function () {
    // Don't use Queue::fake() - we want to actually execute the job
    \Illuminate\Support\Facades\Http::fake([
        '*' => \Illuminate\Support\Facades\Http::response(['message' => 'Log accepted'], 202),
    ]);

    // Create a handler and manually add logs
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    // Simulate logging during a request
    $handler->handle(new \Monolog\LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: \Monolog\Level::Info,
        message: 'Test log from request'
    ));

    // Get logs and dispatch job (simulating what middleware does)
    $logs = $handler->getAllLogs();
    expect($logs)->toHaveCount(1);

    // Actually dispatch and execute the job (using sync connection so it executes immediately)
    $job = new SendLogsJob(
        logs: $logs,
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key'
    );

    // Execute the job directly (simulating sync queue execution)
    $job->handle();

    // Verify HTTP request was actually made
    \Illuminate\Support\Facades\Http::assertSent(function ($request) {
        return $request->url() === 'https://test.flowlog.io/api/v1/logs'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->method() === 'POST'
            && $request->data()['service'] === 'test-service'
            && count($request->data()['logs']) === 1;
    });
});

