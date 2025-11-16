<?php

namespace Flowlog\FlowlogLaravel\Middleware;

use Flowlog\FlowlogLaravel\Context\FlowlogContext;
use Flowlog\FlowlogLaravel\Guards\FlowlogGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class FlowlogMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check for X-Flowlog-Ignore header and set guard if present
        // The presence of the header (regardless of value) indicates logging should be ignored
        $hasIgnoreHeader = $request->hasHeader('X-Flowlog-Ignore');
        $previousIgnoreState = FlowlogGuard::shouldIgnore();
        
        if ($hasIgnoreHeader) {
            FlowlogGuard::setIgnore(true);
        }

        try {
            // Extract or generate iteration key
            $iterationKey = $request->header('X-Iteration-Key') ?? $request->header('X-Flowlog-Iteration-Key');

            // Extract or generate trace ID
            $traceId = $request->header('X-Trace-Id') ?? $request->header('X-Flowlog-Trace-ID');
            if (empty($traceId)) {
                $traceId = (string) Str::uuid();
            }

            // Store in FlowlogContext for access throughout request lifecycle
            FlowlogContext::setIterationKey($iterationKey);
            FlowlogContext::setTraceId($traceId);

            // Add to response headers for client tracking
            $response = $next($request);
            $request->attributes->set('_response_status', $response->getStatusCode());
            if ($iterationKey) {
                $response->headers->set('X-Flowlog-Iteration-Key', $iterationKey);
            }
            
            $response->headers->set('X-Trace-Id', $traceId);

            return $response;
        } finally {
            // Reset the ignore state to its previous value
            FlowlogGuard::setIgnore($previousIgnoreState);
            // Note: We don't clear FlowlogContext here because defer() actions
            // and queued jobs dispatched during the request should still have access
            // to the iteration key. Context will be cleared when appropriate.
        }
    }
}

