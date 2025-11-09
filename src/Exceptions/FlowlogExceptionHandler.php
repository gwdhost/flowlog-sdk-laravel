<?php

namespace Flowlog\FlowlogLaravel\Exceptions;

use Flowlog\FlowlogLaravel\Context\ContextExtractor;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;

class FlowlogExceptionHandler implements ExceptionHandler
{
    protected ContextExtractor $contextExtractor;

    public function __construct(
        protected ExceptionHandler $handler,
        protected $app
    ) {
        $this->contextExtractor = new ContextExtractor();
    }

    /**
     * Report or log an exception.
     */
    public function report(\Throwable $e): void
    {
        // Check if this exception should be reported
        if ($this->shouldReport($e)) {
            $this->reportToFlowlog($e);
        }

        // Call the original handler
        $this->handler->report($e);
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, \Throwable $e)
    {
        return $this->handler->render($request, $e);
    }

    /**
     * Render an exception to the console.
     */
    public function renderForConsole($output, \Throwable $e): void
    {
        $this->handler->renderForConsole($output, $e);
    }

    /**
     * Check if exception should be reported to Flowlog.
     */
    public function shouldReport(\Throwable $e): bool
    {
        $dontReport = config('flowlog.exceptions.dont_report', []);

        foreach ($dontReport as $type) {
            if ($e instanceof $type) {
                return false;
            }
        }

        return true;
    }

    /**
     * Report exception to Flowlog.
     */
    protected function reportToFlowlog(\Throwable $e): void
    {
        try {
            $level = config('flowlog.exceptions.level', 'error');
            $context = $this->contextExtractor->extractExceptionContext($e);

            // Format exception message with context
            $message = sprintf(
                '%s: %s in %s:%d',
                get_class($e),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );

            // Log to Flowlog channel
            Log::channel('flowlog')->{$level}($message, $context);
        } catch (\Exception $reportException) {
            // Silently fail to avoid breaking the application
            // Log to default channel as fallback
            Log::error('Flowlog: Failed to report exception', [
                'original_exception' => $e->getMessage(),
                'report_exception' => $reportException->getMessage(),
            ]);
        }
    }
}

