<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Config Name V2 System
    |--------------------------------------------------------------------------
    |
    | Feature flag to enable/disable the new human-readable config naming system.
    | When enabled, new configs will use the pattern: FP-{PT}-{RSL}-{MODE}-{SEQ}-{H5}
    | When disabled, configs will use the legacy naming convention.
    |
    */
    'enabled' => env('CONFIG_NAME_V2_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Config Name Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix used at the start of all generated config names.
    | Default: FP (FastPanel)
    |
    */
    'prefix' => env('CONFIG_NAME_PREFIX', 'FP'),

    /*
    |--------------------------------------------------------------------------
    | Panel Type Codes
    |--------------------------------------------------------------------------
    |
    | Two-character codes for each panel type.
    | Used in the config name pattern as {PT}.
    |
    */
    'panel_types' => [
        'eylandoo' => 'EY',
        'marzneshin' => 'MN',
        'marzban' => 'MB',
        'xui' => 'XU',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reseller Mode Codes
    |--------------------------------------------------------------------------
    |
    | Single-character codes for reseller types/modes.
    | Used in the config name pattern as {MODE}.
    |
    */
    'mode_codes' => [
        'wallet' => 'W',
        'traffic' => 'T',
    ],

    /*
    |--------------------------------------------------------------------------
    | Collision Retry Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of attempts to generate a unique config name
    | when a collision occurs (extremely rare).
    |
    */
    'collision_retry_limit' => 3,
];
