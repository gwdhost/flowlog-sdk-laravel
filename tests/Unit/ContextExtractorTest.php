<?php

namespace Flowlog\FlowlogLaravel\Tests\Unit;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Flowlog\FlowlogLaravel\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

it('extracts request context', function () {
    // Clear FlowlogContext to ensure we're testing header extraction
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
    
    $request = Request::create('/test', 'GET');
    // Set headers directly using the header() method
    $request->headers->set('X-Request-ID', 'req-123');
    $request->headers->set('X-Trace-Id', 'trace-456');

    app()->instance('request', $request);

    $extractor = new ContextExtractor();
    // Use extractHttpContext directly to test HTTP context extraction
    // (extract() checks runningInConsole and returns console context in tests)
    $context = $extractor->extractHttpContext($request);

    expect($context)->toHaveKey('http_method')
        ->and($context['http_method'])->toBe('GET')
        ->and($context)->toHaveKey('http_host')
        ->and($context)->toHaveKey('request_id')
        ->and($context['request_id'])->toBe('req-123')
        ->and($context)->toHaveKey('trace_id')
        ->and($context['trace_id'])->toBe('trace-456');
    
    // Clean up
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
});

it('generates uuid for missing request id', function () {
    // Clear FlowlogContext to ensure we're testing header extraction
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
    
    $request = Request::create('/test', 'GET');
    app()->instance('request', $request);

    $extractor = new ContextExtractor();
    // Use extractHttpContext directly to test HTTP context extraction
    // (extract() checks runningInConsole and returns console context in tests)
    $context = $extractor->extractHttpContext($request);

    expect($context)->toHaveKey('request_id')
        ->and($context['request_id'])->toBeString()
        ->and(strlen($context['request_id']))->toBeGreaterThan(0);
    
    // Clean up
    \Flowlog\FlowlogLaravel\Context\FlowlogContext::clear();
});

it('extracts exception context', function () {
    // Create an exception - file and line are set automatically by PHP
    $exception = new \Exception('Test exception', 500);
    
    // The exception's file and line are automatically set by PHP
    expect($exception->getFile())->toBeString();
    expect($exception->getLine())->toBeInt();

    $extractor = new ContextExtractor();
    $context = $extractor->extractExceptionContext($exception);

    expect($context)->toHaveKey('exception_class')
        ->and($context['exception_class'])->toBe(\Exception::class)
        ->and($context)->toHaveKey('exception_message')
        ->and($context['exception_message'])->toBe('Test exception')
        ->and($context)->toHaveKey('exception_file')
        ->and($context['exception_file'])->toBeString()
        ->and($context)->toHaveKey('exception_line')
        ->and($context['exception_line'])->toBeInt();
});

