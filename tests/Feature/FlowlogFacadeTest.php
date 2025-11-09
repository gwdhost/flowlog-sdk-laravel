<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Facades\Flowlog;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Support\Facades\Log;

it('can log using facade', function () {
    Log::shouldReceive('channel')
        ->with('flowlog')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->with('Test message', [])
        ->once();

    Flowlog::info('Test message');
});

it('can set iteration key', function () {
    Log::shouldReceive('channel')
        ->with('flowlog')
        ->once()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->with('Test message', ['iteration_key' => 'iter-123'])
        ->once();

    Flowlog::setIterationKey('iter-123')->info('Test message');
});

it('can add context', function () {
    Log::shouldReceive('channel')
        ->with('flowlog')
        ->twice()
        ->andReturnSelf();

    Log::shouldReceive('info')
        ->with('First message', ['feature' => 'checkout'])
        ->once();

    Log::shouldReceive('info')
        ->with('Second message', ['feature' => 'checkout'])
        ->once();

    Flowlog::withContext(['feature' => 'checkout'])
        ->info('First message')
        ->info('Second message');
});

