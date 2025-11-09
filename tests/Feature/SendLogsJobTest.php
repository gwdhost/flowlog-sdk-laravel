<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Support\Facades\Http;

it('sends logs to api', function () {
    Http::fake([
        'test.flowlog.io/*' => Http::response(['message' => 'Log accepted'], 202),
    ]);

    $job = new SendLogsJob(
        logs: [
            [
                'service' => 'test',
                'level' => 'info',
                'timestamp' => now()->toIso8601String(),
                'payload' => json_encode(['message' => 'Test']),
            ],
        ],
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key'
    );

    $job->handle();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test.flowlog.io/api/v1/logs'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $request->method() === 'POST';
    });
});

