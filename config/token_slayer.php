<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client version
    |--------------------------------------------------------------------------
    |
    | Version stamp rendered into the served install scripts and echoed back
    | by hook clients on every event. Bump whenever the install script or
    | hook helper changes so outdated clients become visible in the admin UI.
    |
    */

    'client_version' => '1',

    /*
    |--------------------------------------------------------------------------
    | Anthropic OAuth (server-side quota probing)
    |--------------------------------------------------------------------------
    |
    | Constants for the per-account PKCE OAuth grant the server holds to call
    | the free usage/profile APIs. The client id is Anthropic's public OAuth
    | client for Claude subscriptions — not a secret.
    |
    */

    'anthropic' => [
        'oauth_client_id' => env('ANTHROPIC_OAUTH_CLIENT_ID', '9d1c250a-e61b-44d9-88ed-5944d1962f5e'),
        'token_endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'usage_endpoint' => 'https://api.anthropic.com/api/oauth/usage',
        'profile_endpoint' => 'https://api.anthropic.com/api/oauth/profile',
        'beta_header' => 'oauth-2025-04-20',
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota probing cadence
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'interval_minutes' => (int) env('TOKEN_SLAYER_PROBE_INTERVAL', 5),
        'refresh_headroom_hours' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage snapshot retention
    |--------------------------------------------------------------------------
    */

    'snapshots' => [
        'retention_days' => (int) env('TOKEN_SLAYER_SNAPSHOT_RETENTION_DAYS', 30),
    ],

];
