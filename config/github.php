<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub API access
    |--------------------------------------------------------------------------
    |
    | The server is the authenticated gatekeeper of the slayer-cli wheel: it
    | holds a fine-grained PAT (read-only Contents on the CLI repo) and relays
    | release metadata and asset bytes so the CLI repo can stay private. The
    | client never receives a GitHub URL or credential.
    |
    | Repo is the release channel: each environment points at its own repo, so
    | the same commit tagged in both places yields an identical artifact.
    |
    */

    'token' => env('SLAYER_CLI_GITHUB_TOKEN', ''),

    'cli_repo' => env('SLAYER_CLI_REPO', ''),

    'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),

    /*
    |--------------------------------------------------------------------------
    | Pinned REST API version
    |--------------------------------------------------------------------------
    |
    | GitHub keeps a superseded API version working for at least 24 months, so
    | this is a safety valve: if a version ever breaks the release endpoints,
    | set GITHUB_API_VERSION back to '2022-11-28' in the environment instead of
    | shipping code.
    |
    */

    'api_version' => env('GITHUB_API_VERSION', '2026-03-10'),

    /*
    |--------------------------------------------------------------------------
    | Outbound request identity and timeouts
    |--------------------------------------------------------------------------
    |
    | A non-default User-Agent is mandatory. This server has already been
    | WAF-429'd for sending the HTTP client's stock User-Agent to a vendor API.
    |
    | The metadata timeout is deliberately short: release lookups run during a
    | page render, so a slow GitHub must never hang /profile. The download
    | timeout is longer because it transfers the wheel body (~140 KB).
    |
    */

    'user_agent' => env('GITHUB_USER_AGENT', 'token-slayer-server'),

    'timeout' => (int) env('GITHUB_TIMEOUT', 8),

    'download_timeout' => (int) env('GITHUB_DOWNLOAD_TIMEOUT', 20),

];
