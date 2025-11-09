<?php

namespace Flowlog\FlowlogLaravel\Tests;

use Flowlog\FlowlogLaravel\FlowlogServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            FlowlogServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Setup default config
        $app['config']->set('flowlog.api_url', 'https://test.flowlog.io/api/v1/logs');
        $app['config']->set('flowlog.api_key', 'test-api-key');
        $app['config']->set('flowlog.service', 'test-service');
        $app['config']->set('flowlog.env', 'testing');
    }
}

