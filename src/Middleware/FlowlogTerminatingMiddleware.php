<?php

namespace Flowlog\FlowlogLaravel\Middleware;

use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Flowlog\FlowlogLaravel\Handlers\FlowlogHandler;
use Flowlog\FlowlogLaravel\Jobs\SendLogsJob;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FlowlogTerminatingMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Handle tasks after the response has been sent to the browser.
     * Flush all accumulated logs at request lifecycle end.
     */
    public function terminate(Request $request, Response $response): void
    {
        // Use guard to prevent infinite loops during flush
        FlowlogGuard::whileSending(function () {
            try {
                // Get the FlowlogHandler instance from the log channel
                $logManager = app('log');
                $flowlogChannel = $logManager->channel('flowlog');
                
                if ($flowlogChannel) {
                    $handlers = $flowlogChannel->getHandlers();
                    
                    foreach ($handlers as $handler) {
                        if ($handler instanceof FlowlogHandler) {
                            $logs = $handler->getAllLogs();
                            
                            if (!empty($logs)) {
                                // Dispatch job to send logs (will be chunked if needed)
                                SendLogsJob::dispatch(
                                    $logs,
                                    config('flowlog.api_url'),
                                    config('flowlog.api_key')
                                );
                                
                                // Clear the batch after dispatching
                                $handler->clearBatch();
                            }
                            
                            // Only process the first FlowlogHandler instance
                            break;
                        }
                    }
                }
            } catch (\Exception $e) {
                // Silently fail to prevent breaking the request lifecycle
                // Log to default logger if available
                if (app()->bound('log')) {
                    \Illuminate\Support\Facades\Log::error('Flowlog: Failed to flush logs in terminating middleware', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}

