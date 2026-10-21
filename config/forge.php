<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forge API Token
    |--------------------------------------------------------------------------
    |
    | The token must have the organization:view, server:view and
    | server:manage-php scopes. Always set it in the environment and never
    | pass it as a command line argument.
    |
    */

    'token' => env('FORGE_TOKEN'),

    'base_url' => env('FORGE_API_URL', 'https://forge.laravel.com/api'),

    /*
    |--------------------------------------------------------------------------
    | Request Pacing
    |--------------------------------------------------------------------------
    |
    | Forge allows 60 requests per minute. Every request waits until at least
    | this many seconds have passed since the previous one.
    |
    */

    'seconds_between_requests' => (float) env('FORGE_SECONDS_BETWEEN_REQUESTS', 1.05),

    'max_attempts' => (int) env('FORGE_MAX_ATTEMPTS', 5),

];
