<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum API Keys Per User
    |--------------------------------------------------------------------------
    |
    | This value determines the maximum number of active (non-revoked) API keys
    | that a single user can have at any time. Set to null for unlimited.
    |
    */

    'max_keys_per_user' => env('API_MAX_KEYS_PER_USER', 10),

    /*
    |--------------------------------------------------------------------------
    | API Key Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix is added to all generated API keys to make them easily
    | identifiable.
    |
    */

    'key_prefix' => env('API_KEY_PREFIX', 'vpnm_'),

    /*
    |--------------------------------------------------------------------------
    | Default Rate Limit
    |--------------------------------------------------------------------------
    |
    | Default rate limit for API endpoints (requests per minute).
    |
    */

    'rate_limit' => env('API_RATE_LIMIT', 60),

];
