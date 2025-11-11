<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;

it('accumulates logs in memory without flushing', function () {
    Queue::fake();

    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing',
        batchSize: 3,
        batchInterval: 60,
        maxBatchSizeBytes: 1024 * 1024
    );

    // Add 3 logs - should accumulate but NOT flush automatically
    $handler->handle(new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: Level::Info,
        message: 'Test 1'
    ));

    $handler->handle(new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: Level::Info,
        message: 'Test 2'
    ));

    $handler->handle(new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: Level::Info,
        message: 'Test 3'
    ));

    // Should NOT have dispatched any jobs yet (no automatic flushing)
    Queue::assertNothingPushed();

    // But should have accumulated all logs
    $logs = $handler->getAllLogs();
    expect($logs)->toHaveCount(3);
});

it('can clear batch after flushing', function () {
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    $handler->handle(new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: Level::Info,
        message: 'Test'
    ));

    expect($handler->getAllLogs())->toHaveCount(1);

    $handler->clearBatch();

    expect($handler->getAllLogs())->toHaveCount(0);
});

it('flushes logs when job completes', function () {
    Queue::fake();

    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );

    // Log something
    $handler->handle(new LogRecord(
        datetime: new \DateTimeImmutable(),
        channel: 'test',
        level: Level::Info,
        message: 'Test log from job'
    ));

    // Simulate job completion - flush logs
    $logs = $handler->getAllLogs();
    if (!empty($logs)) {
        \Flowlog\FlowlogLaravel\Jobs\SendLogsJob::dispatch(
            $logs,
            'https://test.flowlog.io/api/v1/logs',
            'test-key'
        );
        $handler->clearBatch();
    }

    // Should have dispatched the job
    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class, function ($job) {
        return count($job->logs) === 1
            && $job->apiUrl === 'https://test.flowlog.io/api/v1/logs'
            && $job->apiKey === 'test-key';
    });
});

