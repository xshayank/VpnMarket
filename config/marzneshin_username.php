<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Marzneshin API Username Generation Configuration
    |--------------------------------------------------------------------------
    |
    | These settings control how usernames are generated for configs created
    | via the Marzneshin-style API. This ONLY affects users/configs created
    | via the Marzneshin API adapter, not those created manually or by other
    | adapters.
    |
    | The format is: <prefix><suffix> (no underscore separator)
    | Example: "ali" becomes "alixy7k"
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Feature Toggle
    |--------------------------------------------------------------------------
    |
    | When enabled, the Marzneshin API will use the new username generation
    | system for user creation. When disabled, the API-provided username
    | is used directly (original behavior).
    |
    */
    'enabled' => env('MARZNESHIN_USERNAME_GENERATION_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Username Prefix Max Length
    |--------------------------------------------------------------------------
    |
    | The maximum length allowed for the sanitized username prefix.
    | Original requested usernames will be trimmed to this length.
    | Shorter than default to produce more compact usernames.
    |
    */
    'prefix_max_len' => (int) env('MARZNESHIN_USERNAME_PREFIX_MAX_LEN', 8),

    /*
    |--------------------------------------------------------------------------
    | Username Suffix Length
    |--------------------------------------------------------------------------
    |
    | The length of the random suffix appended to usernames for uniqueness.
    | Uses base36 characters (0-9, a-z). Shorter for compact usernames.
    |
    */
    'suffix_len' => (int) env('MARZNESHIN_USERNAME_SUFFIX_LEN', 4),

    /*
    |--------------------------------------------------------------------------
    | Allowed Characters Regex
    |--------------------------------------------------------------------------
    |
    | Regular expression pattern for characters to STRIP from usernames.
    | Default strips everything except alphanumeric characters.
    |
    */
    'allowed_chars_regex' => env('MARZNESHIN_USERNAME_ALLOWED_CHARS_REGEX', '/[^a-zA-Z0-9]/'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Prefix
    |--------------------------------------------------------------------------
    |
    | The prefix to use when a requested username results in an empty string
    | after sanitization. Uses 'mz' (short for Marzneshin) as default.
    |
    */
    'fallback_prefix' => env('MARZNESHIN_USERNAME_FALLBACK_PREFIX', 'mz'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Total Username Length
    |--------------------------------------------------------------------------
    |
    | The maximum total length for panel usernames.
    | This includes the prefix and suffix (no underscore separator).
    | Set to a compact default to stay well under panel limits.
    |
    */
    'max_total_len' => (int) env('MARZNESHIN_USERNAME_MAX_TOTAL_LEN', 14),

    /*
    |--------------------------------------------------------------------------
    | Collision Retry Limit
    |--------------------------------------------------------------------------
    |
    | Number of times to retry generating a unique username with random suffix
    | before falling back to numeric increment.
    |
    */
    'collision_retry_limit' => (int) env('MARZNESHIN_USERNAME_COLLISION_RETRY_LIMIT', 5),
];
