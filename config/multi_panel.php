<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-Panel Usage Feature Flag
    |--------------------------------------------------------------------------
    |
    | Enable or disable multi-panel usage aggregation for resellers.
    | When enabled, usage will be fetched from all assigned panels and aggregated.
    | When disabled, only the primary panel will be used (legacy behavior).
    |
    */
    'usage_enabled' => env('MULTI_PANEL_USAGE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Re-enable Batch Size
    |--------------------------------------------------------------------------
    |
    | Number of configs to process in each batch when re-enabling disabled
    | configs across multiple panels. Lower values reduce API load but
    | increase processing time.
    |
    */
    'reenable_batch_size' => env('REENABLE_BATCH_SIZE', 50),

    /*
    |--------------------------------------------------------------------------
    | Panel API Response Cache Duration
    |--------------------------------------------------------------------------
    |
    | Duration in seconds to cache raw panel API responses to reduce load
    | when aggregator runs frequently. Set to 0 to disable caching.
    |
    */
    'api_cache_duration' => env('MULTI_PANEL_API_CACHE_DURATION', 60),

    /*
    |--------------------------------------------------------------------------
    | Concurrent Panel Processing
    |--------------------------------------------------------------------------
    |
    | Whether to process panels concurrently or sequentially when fetching
    | usage data. Concurrent processing is faster but may increase API load.
    |
    */
    'concurrent_processing' => env('MULTI_PANEL_CONCURRENT_PROCESSING', false),
];
