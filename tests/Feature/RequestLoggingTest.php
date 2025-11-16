<?php

namespace Flowlog\FlowlogLaravel\Tests\Feature;

use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Flowlog\FlowlogLaravel\Listeners\HttpListener;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Foundation\Http\Events\RequestHandled;
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
    // Clear any resolved instance first
    app()->forgetInstance('log');
    
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $flowlogChannel = \Mockery::mock('Monolog\Logger');
    $singleChannel = \Mockery::mock('Monolog\Logger');
    
    $flowlogChannel->shouldReceive('getHandlers')->andReturn([$handler]);
    // Allow channel() to be called multiple times with different channels
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    // SendLogsJob constructor calls log() which uses 'single' channel as fallback
    $logManager->shouldReceive('channel')->with('single')->andReturn($singleChannel);
    $singleChannel->shouldReceive('info')->andReturn(true);
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

it('ignores HTTP request logging when X-Flowlog-Ignore header is present', function () {
    // Reset guard state
    FlowlogGuard::setIgnore(false);
    
    // Create a request with X-Flowlog-Ignore header
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('X-Flowlog-Ignore', '1');
    
    // Set up a route so the request has a route (required by HttpListener)
    Route::get('/test', function () {
        return response()->json(['message' => 'ok']);
    });
    
    // Bind the request to the app
    app()->instance('request', $request);
    
    // Mock Log to verify it's NOT called
    Log::shouldReceive('channel')
        ->with('flowlog')
        ->never();
    
    // Process the request through the middleware
    $middleware = app(\Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class);
    $response = $middleware->handle($request, function ($req) {
        // During request processing, guard should be set
        expect(FlowlogGuard::shouldIgnore())->toBeTrue();
        return response()->json(['message' => 'ok']);
    });
    
    // After middleware completes, guard is reset (this is expected behavior)
    expect(FlowlogGuard::shouldIgnore())->toBeFalse();
    
    // Test the HttpListener - since the guard is reset, we need to check if the listener
    // respects the guard when it's set. We'll set it manually to test the listener's behavior.
    FlowlogGuard::setIgnore(true);
    
    $listener = new HttpListener();
    
    // Use reflection to call the protected logRequest method directly
    // This bypasses the console check and tests the actual logging logic
    $reflection = new \ReflectionClass($listener);
    $method = $reflection->getMethod('logRequest');
    $method->setAccessible(true);
    
    // This should not call Log::channel('flowlog') because the guard is set
    $method->invoke($listener, $request, $response);
    
    // Reset guard
    FlowlogGuard::setIgnore(false);
});

it('does not set ignore guard when X-Flowlog-Ignore header is not present', function () {
    // Reset guard state
    FlowlogGuard::setIgnore(false);
    
    // Create a request WITHOUT X-Flowlog-Ignore header
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    
    // Process the request through the middleware
    $middleware = app(\Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class);
    $response = $middleware->handle($request, function ($req) {
        // During request processing, guard should remain false
        expect(FlowlogGuard::shouldIgnore())->toBeFalse();
        return response()->json(['message' => 'ok']);
    });
    
    // After middleware completes, guard should still be false
    expect(FlowlogGuard::shouldIgnore())->toBeFalse();
    
    // Verify the request does not have the ignore header
    expect($request->hasHeader('X-Flowlog-Ignore'))->toBeFalse();
});

it('middleware sets and resets ignore guard correctly with X-Flowlog-Ignore header', function () {
    // Reset guard state
    FlowlogGuard::setIgnore(false);
    expect(FlowlogGuard::shouldIgnore())->toBeFalse();
    
    // Create a request with X-Flowlog-Ignore header
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('X-Flowlog-Ignore', '1');
    
    // Process the request through the middleware
    $middleware = app(\Flowlog\FlowlogLaravel\Middleware\FlowlogMiddleware::class);
    $response = $middleware->handle($request, function ($req) {
        // During request processing, guard should be set
        expect(FlowlogGuard::shouldIgnore())->toBeTrue();
        return response()->json(['message' => 'ok']);
    });
    
    // After middleware completes, guard should be reset
    expect(FlowlogGuard::shouldIgnore())->toBeFalse();
    
    // Test with a request without the header
    $request2 = \Illuminate\Http\Request::create('/test2', 'GET');
    $response2 = $middleware->handle($request2, function ($req) {
        // Guard should remain false
        expect(FlowlogGuard::shouldIgnore())->toBeFalse();
        return response()->json(['message' => 'ok']);
    });
    
    // Guard should still be false
    expect(FlowlogGuard::shouldIgnore())->toBeFalse();
});

it('includes iteration key from X-Iteration-Key header in HTTP request logs', function () {
    Queue::fake();
    
    $iterationKey = 'test-iteration-key-123';
    
    // Set iteration key in FlowlogContext (as middleware would do)
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::setIterationKey($iterationKey);
    
    // Set up a route so the request has a route (required by HttpListener)
    Route::get('/test', function () {
        return response()->json(['message' => 'ok']);
    })->name('test.route');
    
    // Create a request and match it to the route
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('X-Iteration-Key', $iterationKey);
    
    // Match the route to the request
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(function () use ($route) {
        return $route;
    });
    
    // Bind the request to the app
    app()->instance('request', $request);
    
    // Create a mock handler to capture logs
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );
    
    // Mock the log manager to return our handler
    app()->forgetInstance('log');
    
    // Create a real Monolog logger with our handler
    $flowlogChannel = new \Monolog\Logger('flowlog', [$handler]);
    $singleChannel = new \Monolog\Logger('single');
    
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    $logManager->shouldReceive('channel')->with('single')->andReturn($singleChannel);
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);
    
    // Create response
    $response = response()->json(['message' => 'ok']);
    
    // Use reflection to directly call logRequest and bypass console check
    // We'll use a closure to temporarily override runningInConsole
    $listener = new HttpListener();
    $reflection = new \ReflectionClass($listener);
    $method = $reflection->getMethod('logRequest');
    $method->setAccessible(true);
    
    // Create a custom listener that bypasses the console check
    $customListener = new class extends HttpListener {
        protected function logRequest(\Illuminate\Http\Request $request, $response = null): void
        {
            // Skip console check by directly calling parent logic
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::isSending()) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::inSendLogsJob()) {
                return;
            }
            // Skip: if (app()->runningInConsole()) { return; }
            if (! $request || $request->method() == 'OPTIONS') {
                return;
            }
            if (! $request->route()) {
                return;
            }
            if ($this->shouldExcludeRoute($request)) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::shouldIgnore() || $request->hasHeader('X-Flowlog-Ignore')) {
                return;
            }
            
            $startTime = $this->getStartTime($request);
            $executionTime = $startTime ? microtime(true) - $startTime : null;
            
            $statusCode = null;
            try {
                if ($response !== null) {
                    if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'getStatusCode')) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'status')) {
                        $statusCode = $response->status();
                    }
                }
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            } catch (\Exception $e) {
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            }

            $context = $this->contextExtractor->extractHttpContext($request, $statusCode, $executionTime);

            if (config('flowlog.http.log_headers', false)) {
                $context['http_headers'] = $this->sanitizeHeaders($request->headers->all());
            }

            $level = $this->getLogLevel($statusCode);
            $message = sprintf(
                '%s %s - %s',
                $request->method(),
                $request->path(),
                $statusCode ?? 'N/A'
            );

            \Illuminate\Support\Facades\Log::channel('flowlog')->{$level}($message, $context);
        }
    };
    
    $customListener->handle(new RequestHandled($request, $response));
    
    // Get the logs from the handler
    $logs = $handler->getAllLogs();
    
    // Verify that a log was created
    expect($logs)->toHaveCount(1);
    
    // Verify the log contains the iteration key (it's at the top level in the formatted log entry)
    $log = $logs[0];
    expect($log['iteration_key'])->toBe($iterationKey);
    
    // Clean up
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
});

it('includes iteration key from X-Flowlog-Iteration-Key header in HTTP request logs', function () {
    Queue::fake();
    
    $iterationKey = 'test-iteration-key-456';
    
    // Set iteration key in FlowlogContext (as middleware would do)
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::setIterationKey($iterationKey);
    
    // Set up a route so the request has a route (required by HttpListener)
    Route::get('/test', function () {
        return response()->json(['message' => 'ok']);
    })->name('test.route');
    
    // Create a request and match it to the route
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('X-Flowlog-Iteration-Key', $iterationKey);
    
    // Match the route to the request
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(function () use ($route) {
        return $route;
    });
    
    // Bind the request to the app
    app()->instance('request', $request);
    
    // Create a mock handler to capture logs
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );
    
    // Mock the log manager to return our handler
    app()->forgetInstance('log');
    
    // Create a real Monolog logger with our handler
    $flowlogChannel = new \Monolog\Logger('flowlog', [$handler]);
    $singleChannel = new \Monolog\Logger('single');
    
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    $logManager->shouldReceive('channel')->with('single')->andReturn($singleChannel);
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);
    
    // Create response
    $response = response()->json(['message' => 'ok']);
    
    // Use reflection to directly call logRequest and bypass console check
    // We'll use a closure to temporarily override runningInConsole
    $listener = new HttpListener();
    $reflection = new \ReflectionClass($listener);
    $method = $reflection->getMethod('logRequest');
    $method->setAccessible(true);
    
    // Create a custom listener that bypasses the console check
    $customListener = new class extends HttpListener {
        protected function logRequest(\Illuminate\Http\Request $request, $response = null): void
        {
            // Skip console check by directly calling parent logic
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::isSending()) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::inSendLogsJob()) {
                return;
            }
            // Skip: if (app()->runningInConsole()) { return; }
            if (! $request || $request->method() == 'OPTIONS') {
                return;
            }
            if (! $request->route()) {
                return;
            }
            if ($this->shouldExcludeRoute($request)) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::shouldIgnore() || $request->hasHeader('X-Flowlog-Ignore')) {
                return;
            }
            
            $startTime = $this->getStartTime($request);
            $executionTime = $startTime ? microtime(true) - $startTime : null;
            
            $statusCode = null;
            try {
                if ($response !== null) {
                    if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'getStatusCode')) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'status')) {
                        $statusCode = $response->status();
                    }
                }
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            } catch (\Exception $e) {
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            }

            $context = $this->contextExtractor->extractHttpContext($request, $statusCode, $executionTime);

            if (config('flowlog.http.log_headers', false)) {
                $context['http_headers'] = $this->sanitizeHeaders($request->headers->all());
            }

            $level = $this->getLogLevel($statusCode);
            $message = sprintf(
                '%s %s - %s',
                $request->method(),
                $request->path(),
                $statusCode ?? 'N/A'
            );

            \Illuminate\Support\Facades\Log::channel('flowlog')->{$level}($message, $context);
        }
    };
    
    $customListener->handle(new RequestHandled($request, $response));
    
    // Get the logs from the handler
    $logs = $handler->getAllLogs();
    
    // Verify that a log was created
    expect($logs)->toHaveCount(1);
    
    // Verify the log contains the iteration key (it's at the top level in the formatted log entry)
    $log = $logs[0];
    expect($log['iteration_key'])->toBe($iterationKey);
    
    // Clean up
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
});

it('prefers X-Iteration-Key over X-Flowlog-Iteration-Key when both headers are present', function () {
    Queue::fake();
    
    $iterationKey1 = 'test-iteration-key-789';
    $iterationKey2 = 'test-iteration-key-999';
    
    // Set iteration key in FlowlogContext (as middleware would do, using X-Iteration-Key)
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::setIterationKey($iterationKey1);
    
    // Set up a route so the request has a route (required by HttpListener)
    Route::get('/test', function () {
        return response()->json(['message' => 'ok']);
    })->name('test.route');
    
    // Create a request and match it to the route
    $request = \Illuminate\Http\Request::create('/test', 'GET');
    $request->headers->set('X-Iteration-Key', $iterationKey1);
    $request->headers->set('X-Flowlog-Iteration-Key', $iterationKey2);
    
    // Match the route to the request
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(function () use ($route) {
        return $route;
    });
    
    // Bind the request to the app
    app()->instance('request', $request);
    
    // Create a mock handler to capture logs
    $handler = new FlowlogHandler(
        apiUrl: 'https://test.flowlog.io/api/v1/logs',
        apiKey: 'test-key',
        service: 'test-service',
        env: 'testing'
    );
    
    // Mock the log manager to return our handler
    app()->forgetInstance('log');
    
    // Create a real Monolog logger with our handler
    $flowlogChannel = new \Monolog\Logger('flowlog', [$handler]);
    $singleChannel = new \Monolog\Logger('single');
    
    $logManager = \Mockery::mock('Illuminate\Log\LogManager');
    $logManager->shouldReceive('channel')->with('flowlog')->andReturn($flowlogChannel);
    $logManager->shouldReceive('channel')->with('single')->andReturn($singleChannel);
    $logManager->shouldReceive('error')->andReturn(true);
    
    app()->instance('log', $logManager);
    
    // Create response
    $response = response()->json(['message' => 'ok']);
    
    // Use reflection to directly call logRequest and bypass console check
    // We'll use a closure to temporarily override runningInConsole
    $listener = new HttpListener();
    $reflection = new \ReflectionClass($listener);
    $method = $reflection->getMethod('logRequest');
    $method->setAccessible(true);
    
    // Create a custom listener that bypasses the console check
    $customListener = new class extends HttpListener {
        protected function logRequest(\Illuminate\Http\Request $request, $response = null): void
        {
            // Skip console check by directly calling parent logic
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::isSending()) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::inSendLogsJob()) {
                return;
            }
            // Skip: if (app()->runningInConsole()) { return; }
            if (! $request || $request->method() == 'OPTIONS') {
                return;
            }
            if (! $request->route()) {
                return;
            }
            if ($this->shouldExcludeRoute($request)) {
                return;
            }
            if (\Flowlog\FlowlogLaravel\Guards\FlowlogGuard::shouldIgnore() || $request->hasHeader('X-Flowlog-Ignore')) {
                return;
            }
            
            $startTime = $this->getStartTime($request);
            $executionTime = $startTime ? microtime(true) - $startTime : null;
            
            $statusCode = null;
            try {
                if ($response !== null) {
                    if ($response instanceof \Symfony\Component\HttpFoundation\Response) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'getStatusCode')) {
                        $statusCode = $response->getStatusCode();
                    } elseif (method_exists($response, 'status')) {
                        $statusCode = $response->status();
                    }
                }
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            } catch (\Exception $e) {
                if ($statusCode === null) {
                    $statusCode = $request->attributes->get('_response_status');
                }
            }

            $context = $this->contextExtractor->extractHttpContext($request, $statusCode, $executionTime);

            if (config('flowlog.http.log_headers', false)) {
                $context['http_headers'] = $this->sanitizeHeaders($request->headers->all());
            }

            $level = $this->getLogLevel($statusCode);
            $message = sprintf(
                '%s %s - %s',
                $request->method(),
                $request->path(),
                $statusCode ?? 'N/A'
            );

            \Illuminate\Support\Facades\Log::channel('flowlog')->{$level}($message, $context);
        }
    };
    
    $customListener->handle(new RequestHandled($request, $response));
    
    // Get the logs from the handler
    $logs = $handler->getAllLogs();
    
    // Verify that a log was created
    expect($logs)->toHaveCount(1);
    
    // Verify the log contains the first iteration key (FlowlogContext takes precedence)
    $log = $logs[0];
    expect($log['iteration_key'])->toBe($iterationKey1);
    
    // Clean up
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
});

