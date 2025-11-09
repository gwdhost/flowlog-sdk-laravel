<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Support\Facades\Queue;
use Monolog\Level;
use Monolog\LogRecord;

it('batches logs before sending', function () {
    Queue::fake();

    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing',
        batchSize: 3, // Small batch for testing
        batchInterval: 60, // Long interval
        maxBatchSizeBytes: 1024 * 1024
    );

    // Add 3 logs (should trigger flush)
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

    Queue::assertPushed(\Flowlog\FlowlogLaravel\Jobs\SendLogsJob::class, function ($job) {
        return count($job->logs) === 3;
    });
});

