<?php

namespace Flowlog\FlowlogLaravel;

use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Listeners\HttpListener;
use Flowlog\FlowlogLaravel\Listeners\JobListener;
use Flowlog\FlowlogLaravel\Listeners\QueryListener;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Monolog\Logger;
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

        // Register custom log channel
        $this->registerLogChannel();
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

        $this->app->extend('log', function ($manager, $app) {
            $manager->extend('flowlog', function ($app, $config) {
                return new Logger('flowlog', [
                    new FlowlogHandler(
                        apiUrl: config('flowlog.api_url'),
                        apiKey: config('flowlog.api_key'),
                        service: config('flowlog.service', 'laravel'),
                        env: config('flowlog.env', 'local'),
                        batchSize: config('flowlog.batch.size', 50),
                        batchInterval: config('flowlog.batch.interval', 5),
                        maxBatchSizeBytes: config('flowlog.batch.max_size_bytes', 64 * 1024)
                    ),
                ]);
            });

            return $manager;
        });
    }

    /**
     * Register event listeners for automatic logging.
     */
    protected function registerEventListeners(): void
    {
        $this->app->booted(function () {    
            // Query logging
            if (config('flowlog.features.query_logging', false)) {
                Event::listen(QueryExecuted::class, [QueryListener::class, 'handle']);
            }

            // HTTP logging - Use RequestHandled event (proper Laravel way for versions 10, 11, and 12)
            if (config('flowlog.features.http_logging', false)) {
                Event::listen(RequestHandled::class, [HttpListener::class, 'handle']);
            }
        });

        // Job/Queue logging (enabled by default)
        if (config('flowlog.features.job_logging', true)) {
            $jobListener = $this->app->make(JobListener::class);
            Event::listen(JobProcessing::class, [$jobListener, 'handleProcessing']);
            Event::listen(JobProcessed::class, [$jobListener, 'handleProcessed']);
            Event::listen(JobFailed::class, [$jobListener, 'handleFailed']);
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

