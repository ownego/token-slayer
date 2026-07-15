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

    'client_version' => '1.0.0',

    /*
    |--------------------------------------------------------------------------
    | Anthropic OAuth (server-side quota probing)
    |--------------------------------------------------------------------------
    |
    | Constants for the per-account PKCE OAuth grant the server holds to call
    | the free usage/profile APIs. The client id is Anthropic's public OAuth
    | client for Claude subscriptions — not a secret.
    |
    | `user_agent` is load-bearing: platform.claude.com's WAF returns a bare
    | 429 (no Retry-After) for the default Guzzle/curl/browser User-Agent, so
    | every request must send a claude-cli-style UA instead. `redirect_uri`
    | is the manual/paste PKCE callback used by the connect flow.
    |
    */

    'anthropic' => [
        'oauth_client_id' => env('ANTHROPIC_OAUTH_CLIENT_ID', '9d1c250a-e61b-44d9-88ed-5944d1962f5e'),
        'token_endpoint' => 'https://platform.claude.com/v1/oauth/token',
        'usage_endpoint' => 'https://api.anthropic.com/api/oauth/usage',
        'profile_endpoint' => 'https://api.anthropic.com/api/oauth/profile',
        'beta_header' => 'oauth-2025-04-20',
        'user_agent' => env('ANTHROPIC_USER_AGENT', 'claude-cli/2.1.206 (external, cli)'),
        'redirect_uri' => env('ANTHROPIC_OAUTH_REDIRECT_URI', 'https://platform.claude.com/oauth/code/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quota probing cadence
    |--------------------------------------------------------------------------
    */

    'probe' => [
        'refresh_headroom_hours' => (int) env('TOKEN_SLAYER_PROBE_HEADROOM_HOURS', 4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Usage snapshot retention
    |--------------------------------------------------------------------------
    */

    'snapshots' => [
        'retention_days' => (int) env('TOKEN_SLAYER_SNAPSHOT_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | slayer-cli release source
    |--------------------------------------------------------------------------
    |
    | slayer-cli lives in its own PRIVATE repo and ships as a GitHub Release
    | asset. Because the repo is private, an anonymous install script can't
    | download the asset directly (GitHub 404s unauthenticated release-asset
    | requests on private repos) — so this server fetches it server-side with
    | a repo-scoped token and streams the bytes back, never storing a copy.
    |
    | `github_token` needs only `contents: read` on `github_repo` (fine-
    | grained PAT). Empty until configured — the controller 404s cleanly
    | rather than fetching nothing.
    |
    */

    'slayer_cli' => [
        'github_repo' => env('SLAYER_CLI_GITHUB_REPO', ''),
        'github_token' => env('SLAYER_CLI_GITHUB_TOKEN', ''),
        'wheel_asset_name' => env('SLAYER_CLI_WHEEL_ASSET', 'slayer_cli-latest.whl'),
    ],

];
