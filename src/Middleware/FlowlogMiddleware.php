<?php

namespace Flowlog\FlowlogLaravel\Middleware;

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
        // Extract or generate iteration key
        $iterationKey = $request->header('X-Iteration-Key') ?? $request->header('X-Flowlog-Iteration-Key');

        // Extract or generate trace ID
        $traceId = $request->header('X-Trace-Id') ?? $request->header('X-Flowlog-Trace-ID');
        if (empty($traceId)) {
            $traceId = (string) Str::uuid();
        }

        // Store in request for context extraction
        $request->merge([
            'flowlog_iteration_key' => $iterationKey,
            'flowlog_trace_id' => $traceId,
        ]);

        // Add to response headers for client tracking
        $response = $next($request);
        $request->attributes->set('_response_status', $response->getStatusCode());
        if ($iterationKey) {
            $response->headers->set('X-Flowlog-Iteration-Key', $iterationKey);
        }
        
        $response->headers->set('X-Trace-Id', $traceId);

        return $response;
    }
}

