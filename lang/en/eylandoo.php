<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Eylandoo API Error Messages
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for Eylandoo API error messages.
    |
    */

    'username_required' => 'Username is required.',
    'connection_failed' => 'Unable to connect to panel. Please try again later.',
    'unexpected_error' => 'Unexpected error: :message',
    'panel_offline' => 'Eylandoo panel is offline. Please try again later.',
    'provision_failed' => 'Failed to provision config on the panel.',
    'credentials_missing' => 'Eylandoo panel credentials are not configured.',
    'create_error' => 'Error creating config: :message',

    // HTTP status code messages
    'http_400' => 'Invalid request.',
    'http_401' => 'Unauthorized access. API key is invalid.',
    'http_403' => 'Access denied. You do not have permission for this operation.',
    'http_404' => 'Resource not found.',
    'http_409' => 'Username already exists.',
    'http_422' => 'Invalid input data.',
    'http_429' => 'Too many requests.',
    'http_5xx' => 'Panel server error. Please try again later.',
    'http_default' => 'Panel error (code :code)',
];
