<?php

if (! function_exists('flowlog')) {
    /**
     * Get the Flowlog instance.
     */
    function flowlog(): \Flowlog\FlowlogLaravel\Flowlog
    {
        return app(\Flowlog\FlowlogLaravel\Flowlog::class);
    }
}

