<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Username Generation Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how usernames are generated for panel interactions,
    | particularly for Marzneshin and other VPN panel APIs.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Username Prefix Max Length
    |--------------------------------------------------------------------------
    |
    | The maximum length allowed for the sanitized username prefix.
    | Original requested usernames will be trimmed to this length.
    |
    */
    'prefix_max_len' => (int) env('USERNAME_PREFIX_MAX_LEN', 12),

    /*
    |--------------------------------------------------------------------------
    | Username Suffix Length
    |--------------------------------------------------------------------------
    |
    | The length of the random suffix appended to usernames for uniqueness.
    | Uses base36 characters (0-9, a-z).
    |
    */
    'suffix_len' => (int) env('USERNAME_SUFFIX_LEN', 6),

    /*
    |--------------------------------------------------------------------------
    | Allowed Characters Regex
    |--------------------------------------------------------------------------
    |
    | Regular expression pattern for characters to STRIP from usernames.
    | Default strips everything except alphanumeric characters.
    |
    */
    'allowed_chars_regex' => env('USERNAME_ALLOWED_CHARS_REGEX', '/[^a-zA-Z0-9]/'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix to use when a requested username results in an empty string
    | after sanitization.
    |
    */
    'fallback_prefix' => env('USERNAME_FALLBACK_PREFIX', 'user'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Total Username Length
    |--------------------------------------------------------------------------
    |
    | The maximum total length for panel usernames.
    | This includes the prefix, underscore, and suffix.
    | Set to stay well under panel limits (typically 32-64 chars).
    |
    */
    'max_total_len' => (int) env('USERNAME_MAX_TOTAL_LEN', 20),

    /*
    |--------------------------------------------------------------------------
    | Collision Retry Limit
    |--------------------------------------------------------------------------
    |
    | Number of times to retry generating a unique username with random suffix
    | before falling back to numeric increment.
    |
    */
    'collision_retry_limit' => (int) env('USERNAME_COLLISION_RETRY_LIMIT', 5),
];
