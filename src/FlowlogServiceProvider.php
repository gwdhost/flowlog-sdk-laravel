<?php

namespace Flowlog\FlowlogLaravel;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Listeners\HttpListener;
use Flowlog\FlowlogLaravel\Listeners\JobListener;
use Flowlog\FlowlogLaravel\Listeners\QueryListener;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\LogManager;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class FlowlogServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/flowlog.php',
            'flowlog'
        );

        // Register Flowlog as singleton
        $this->app->singleton(Flowlog::class, function ($app) {
            return new Flowlog();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__.'/../config/flowlog.php' => config_path('flowlog.php'),
        ], 'flowlog-config');

        // Register custom log channel
        $this->registerLogChannel();

        // Register event listeners
        $this->registerEventListeners();
    }

    /**
     * Register the custom Flowlog log channel.
     */
    protected function registerLogChannel(): void
    {
        if (! config('flowlog.api_key')) {
            return; // Don't register if API key is not configured
        }

        $this->app->make(LogManager::class)->extend('flowlog', function ($app, $config) {
            return new \Monolog\Logger('flowlog', [
                new FlowlogHandler(
                    apiUrl: config('flowlog.api_url'),
                    apiKey: config('flowlog.api_key'),
                    service: config('flowlog.service'),
                    env: config('flowlog.env'),
                    batchSize: config('flowlog.batch.size', 50),
                    batchInterval: config('flowlog.batch.interval', 5),
                    maxBatchSizeBytes: config('flowlog.batch.max_size_bytes', 64 * 1024)
                ),
            ]);
        });
    }

    /**
     * Register event listeners for automatic logging.
     */
    protected function registerEventListeners(): void
    {
        // Query logging
        if (config('flowlog.features.query_logging', false)) {
            Event::listen(QueryExecuted::class, QueryListener::class);
        }

        // HTTP logging
        if (config('flowlog.features.http_logging', false)) {
            $this->app->singleton(HttpListener::class);
            $this->app->make(HttpListener::class)->register();
        }

        // Job/Queue logging (enabled by default)
        if (config('flowlog.features.job_logging', true)) {
            Event::listen(JobProcessing::class, [JobListener::class, 'handleProcessing']);
            Event::listen(JobProcessed::class, [JobListener::class, 'handleProcessed']);
            Event::listen(JobFailed::class, [JobListener::class, 'handleFailed']);
        }

        // Exception reporting
        if (config('flowlog.features.exception_reporting', true)) {
            $this->registerExceptionReporting();
        }
    }

    /**
     * Register exception reporting hook.
     */
    protected function registerExceptionReporting(): void
    {
        $handler = $this->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        // Use reporting callback if available (Laravel 11+)
        if (method_exists($handler, 'reporting')) {
            $handler->reporting(function (\Throwable $e) {
                $flowlogHandler = new Exceptions\FlowlogExceptionHandler($handler, $this->app);
                $flowlogHandler->report($e);
            });
        } else {
            // Fallback: extend the handler for older Laravel versions
            $this->app->extend(\Illuminate\Contracts\Debug\ExceptionHandler::class, function ($handler, $app) {
                return new Exceptions\FlowlogExceptionHandler($handler, $app);
            });
        }
    }
}

