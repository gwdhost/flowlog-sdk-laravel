<?php

namespace Flowlog\FlowlogLaravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Flowlog\FlowlogLaravel\Flowlog setIterationKey(string $iterationKey)
 * @method static \Flowlog\FlowlogLaravel\Flowlog setTraceId(string $traceId)
 * @method static \Flowlog\FlowlogLaravel\Flowlog withContext(array $context)
 * @method static \Flowlog\FlowlogLaravel\Flowlog clearContext()
 * @method static void info(string $message, array $context = [])
 * @method static void error(string $message, array $context = [])
 * @method static void warn(string $message, array $context = [])
 * @method static void debug(string $message, array $context = [])
 * @method static void critical(string $message, array $context = [])
 * @method static void reportException(\Throwable $exception, array $context = [])
 */
class Flowlog extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \Flowlog\FlowlogLaravel\Flowlog::class;
    }
}

