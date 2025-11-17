<?php

return [
    'traffic_rate_per_gb' => env('TRAFFIC_RESELLER_GB_RATE', 750),
    'min_first_traffic_topup_gb' => env('MIN_FIRST_TRAFFIC_TOPUP_GB', 250),
    'min_traffic_topup_gb' => env('MIN_TRAFFIC_TOPUP_GB', 50),

    /*
    |--------------------------------------------------------------------------
    | Wallet-based Reseller Billing Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for wallet-based reseller hourly billing system.
    |
    */

    'wallet' => [
        /*
         * Default price per GB for wallet-based resellers (in تومان)
         * This is used when a reseller doesn't have a custom price override
         */
        'price_per_gb' => env('WALLET_PRICE_PER_GB', 780),

        /*
         * Suspension threshold (in تومان)
         * When a wallet-based reseller's balance drops to or below this value,
         * their account will be suspended and all configs disabled
         */
        'suspension_threshold' => env('WALLET_SUSPENSION_THRESHOLD', -1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reseller-Only Architecture Configuration
    |--------------------------------------------------------------------------
    |
    | Thresholds and rates for the reseller-only system where all users
    | are created as resellers (wallet-based or traffic-based).
    |
    */

    'reseller' => [
        /*
         * First-time top-up thresholds
         * New resellers must meet these minimums to activate their account
         */
        'first_topup' => [
            'wallet_min' => env('MIN_FIRST_WALLET_TOPUP', 150000),  // تومان
            'traffic_min_gb' => env('MIN_FIRST_TRAFFIC_TOPUP_GB', 250),  // GB
        ],

        /*
         * Subsequent top-up minimums (after first activation)
         */
        'min_topup' => [
            'wallet' => env('MIN_WALLET_TOPUP', 50000),  // تومان
            'traffic_gb' => env('MIN_TRAFFIC_TOPUP_GB', 50),  // GB
        ],

        /*
         * Traffic pricing for traffic-based resellers
         */
        'traffic' => [
            'price_per_gb' => env('TRAFFIC_RESELLER_GB_RATE', 750),  // تومان per GB
        ],

        /*
         * Config limits per reseller type
         */
        'config_limits' => [
            'wallet' => 1000,  // Maximum configs for wallet-based resellers
            'traffic' => null, // Unlimited for traffic-based resellers
        ],
    ],
];
