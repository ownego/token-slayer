<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Hook version
    |--------------------------------------------------------------------------
    |
    | The version of the hook shipped by THIS repo. Deliberately separate from
    | the slayer-cli release tag: that tag lives in a repo this project does not
    | publish, so a hook-only change would otherwise ship with an unchanged
    | version and every update prompt would silently no-op. Bump this whenever
    | the hook template changes in a way clients must pick up.
    |
    */

    'hook_version' => (string) env('TOKEN_SLAYER_HOOK_VERSION', '7'),

    /*
    |--------------------------------------------------------------------------
    | Update pause
    |--------------------------------------------------------------------------
    |
    | Fleet-wide kill switch for client self-update. Flipping this true stops
    | every machine from updating on its next ingest round-trip, so a bad
    | release can be halted centrally instead of by reaching each developer.
    |
    */

    'updates_paused' => (bool) env('TOKEN_SLAYER_UPDATES_PAUSED', false),

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
        'messages_endpoint' => 'https://api.anthropic.com/v1/messages',
        'version_header' => '2023-06-01',
        'beta_header' => 'oauth-2025-04-20',
        'user_agent' => env('ANTHROPIC_USER_AGENT', 'claude-cli/2.1.206 (external, cli)'),
        'redirect_uri' => env('ANTHROPIC_OAUTH_REDIRECT_URI', 'https://platform.claude.com/oauth/code/callback'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Session anchoring
    |--------------------------------------------------------------------------
    |
    | A minimal real inference (max_tokens 1) is sent to each account at fixed
    | clock times so Anthropic's rolling 5-hour usage window starts then. A
    | 0-token usage/beacon call does NOT start a session, so this must be a
    | genuine (tiny) message. `model` is the cheapest valid model to bill the
    | one token against.
    |
    */

    'session_anchor' => [
        'model' => env('TOKEN_SLAYER_SESSION_ANCHOR_MODEL', 'claude-haiku-4-5-20251001'),
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
    | Account rebalance recommender
    |--------------------------------------------------------------------------
    |
    | Tunables for AccountCapacityEstimator/UserDemandEstimator/
    | AccountRebalanceRecommender. trend_window_days is the default lookback
    | when the admin has not picked a range on the Rebalance page;
    | safety_margin_percent is how much of every account's capacity is left
    | unplanned; min_history_days is the minimum days of data an account/user
    | needs before a recommendation involving it is marked confident.
    |
    | The peak-demand window is deliberately NOT tunable: a demand figure is
    | only comparable with a weekly quota if it is measured over a week.
    |
    | members_per_account is how many people an account should carry. Weekly
    | load alone does not capture the risk of a crowded account: more people
    | means more of them working at the same time, and a 5-hour window is
    | tripped by simultaneity rather than by a weekly total. It is a target
    | rather than a hard limit -- when there are more people than it allows,
    | it rises to the smallest number that fits everyone.
    |
    */

    'rebalance' => [
        'trend_window_days' => (int) env('TOKEN_SLAYER_REBALANCE_TREND_DAYS', 14),
        'members_per_account' => (int) env('TOKEN_SLAYER_REBALANCE_MEMBERS_PER_ACCOUNT', 5),
        'safety_margin_percent' => (int) env('TOKEN_SLAYER_REBALANCE_SAFETY_MARGIN', 20),
        'min_history_days' => (int) env('TOKEN_SLAYER_REBALANCE_MIN_HISTORY_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Battlefield sky site
    |--------------------------------------------------------------------------
    |
    | Where the battlefield's living sky (sun/moon position, day/night colour)
    | is computed for. The viewer's own clock drives the time; this only sets
    | the place. Defaults to Hanoi.
    |
    */

    'sky' => [
        'lat' => (float) env('TOKEN_SLAYER_SKY_LAT', 21.03),
        'lon' => (float) env('TOKEN_SLAYER_SKY_LON', 105.85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom character pose sheet limits
    |--------------------------------------------------------------------------
    |
    | What an uploaded pose sheet may look like before we spend any time on it.
    | max_pixels bounds the decoded bitmap (a tiny PNG can still expand to
    | gigabytes), so it is checked from the header before decoding.
    |
    */

    'custom_character' => [
        'max_bytes' => 4 * 1024 * 1024,
        'min_side' => 512,
        'max_side' => 4096,
        'max_pixels' => 16_000_000,
        // Uploads a user may attempt per hour; building a sheet costs about a second of CPU.
        'uploads_per_hour' => 10,
        // Bump when the sheet layout or the prompt below changes in a way that invalidates sheets made with the old one.
        'prompt_version' => 1,
        // The prompt users paste into an image AI, together with a picture of their character.
        'prompt' => <<<'PROMPT'
This is my original character. Redraw the SAME character (same face, head shape, fur/skin/hair colors, body proportions, and signature features such as glasses, ears, tail or hairstyle) as a medieval fantasy adventurer in the style of a classic 16-bit pixel-art RPG.

COSTUME (required): replace ALL modern clothing and accessories with medieval fantasy gear: a leather-and-cloth tunic, a small metal pauldron or bracer, a belt, boots and a short cape or scarf, and a short sword in the hand. If the character has a signature item (such as glasses or a hat), keep it but make it fit the fantasy look. No modern items (no phones, logos, text, sneakers, hoodies, jeans, suits).

COLORS: muted, slightly desaturated medieval palette (earthy browns, dull steel greys, deep reds, forest greens). Keep the character's own main colors recognisable but toned down. Thick dark outline, flat shading with at most 2 tones per color, no gradients, no glow, no shadows, no motion lines, no impact effects, no text.

OUTPUT: ONE image on a flat solid magenta (#FF00FF) background, no grid lines, no borders. 4 rows, all poses the same size, feet on the same baseline within a row, character facing RIGHT, with clear empty space between poses:
- Row 1: idle, standing, 4 poses.
- Row 2: walk cycle, pure side profile, exactly 4 poses.
- Row 3: sword slash attack, exactly 3 poses (wind up, strike, follow through).
- Row 4: defeated, exactly 3 poses, eyes closed, falling then lying on the ground.
PROMPT,
        // Height in px the idle/walk poses are shrunk to, before the outline is added.
        'sprite_height' => 28,
        // Colours shared by every pose after quantising.
        'palette_colors' => 24,
        'outline_color' => '#16121f',
        // How far an idle/walk pose's height may stray from the median, as a fraction of it.
        'size_tolerance' => 0.3,
        // A detached ink blob smaller than this share of its pose's ink is a stray mark (motion lines, sparks).
        'stray_ratio' => 0.03,
        // Multipliers pulling an uploaded character's colours toward the roster's muted look.
        'tone_saturation' => 0.85,
        'tone_lightness' => 0.95,
        // Rows top to bottom, each with the pose count it must have.
        'rows' => [
            ['name' => 'idle', 'min' => 2, 'max' => 4],
            ['name' => 'walk', 'min' => 4, 'max' => 4],
            ['name' => 'attack', 'min' => 3, 'max' => 3],
            ['name' => 'death', 'min' => 3, 'max' => 3],
        ],
    ],

];
