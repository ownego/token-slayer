# Token Slayer

A cooperative idle boss raid for your team. Every token your AI agents spend
(Claude Code, Codex, Antigravity, Claude Cowork, and claude.ai chats) becomes
damage against the current boss. Hits land in real time on a shared Phaser
battlefield, the team defeats bosses together, and kills are announced in Slack.

## How it works

1. Sign in with Slack and open `/setup`.
2. Run the installer it gives you. It installs the `tok` CLI and registers
   hooks for Claude Code, Codex, and Antigravity.
3. When an agent finishes a turn, the hook reads the token usage locally and
   posts it to `POST /api/events`. Only whitelisted fields leave the machine;
   prompts, tool input, and file paths never do.
4. The server records the event, applies damage to the boss, and broadcasts
   the hit over Reverb to everyone watching `/battlefield`.
5. When the boss falls, the kill goes to Slack and a new boss spawns.

The full pipeline is documented in `.ai/domain/token-tracking.md`.

## Tech stack

- **Laravel 13** on PHP 8.4
- **Livewire 4** + **Tailwind CSS 4** for the pages
- **Filament 5** for the admin dashboard (`/dashboard`)
- **Phaser 3** for the battlefield
- **Laravel Reverb** for websockets, **Redis** for the queue
- **Laravel Socialite** for Slack login
- **PostgreSQL** in deployed environments, **SQLite** for local dev
- **Pest 4** and **Vitest** for tests

## Local setup

The project runs in Docker through [Spin](https://serversideup.net/open-source/spin/).
Use `spin exec php …` for every PHP command. Bare `php` on the host targets the
wrong environment.

```bash
cp .env.example .env
spin up
spin exec php composer install
spin exec php php artisan key:generate
spin exec php php artisan migrate
npm install
npm run build
```

`docker-compose.dev.yml` starts `php`, `reverb`, `worker` (the queue), `pgsql`,
and `redis`. If the PHP container is stopped, run `docker start token-slayer-php-1`.

Run `npm run build` after every JS or CSS change. It also packs the battlefield
sprite sheets.

### Environment variables

The ones you need to fill in:

| Var | Notes |
| --- | --- |
| `SLACK_OAUTH_CLIENT_ID` | Slack app used for "Sign in with Slack". |
| `SLACK_OAUTH_CLIENT_SECRET` | Same Slack app. |
| `SLACK_OAUTH_REDIRECT_URI` | Defaults to `${APP_URL}/auth/slack/callback`. |
| `SLACK_BOT_TOKEN` | Bot token (`users:read`) used to read members' display names. |
| `SLACK_NOTIFIER_WEBHOOK_URL` | Incoming webhook for boss-fight announcements. |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | Reverb credentials. |

Optional:

| Var | Notes |
| --- | --- |
| `HOOK_NAMESPACE` | Identifier baked into the installer. Defaults to a slug of `APP_NAME`. |
| `GAME_BASE_HP` | Base boss HP. Defaults to `1000000`. |
| `GAME_IDLE_MINUTES` | Minutes before an idle fighter leaves the field. Defaults to `30`. |
| `GAME_SUBAGENT_IDLE_SECONDS` | Seconds before an idle subagent minion leaves. Defaults to `60`. |
| `SLACK_SECURITY_BOT_TOKEN` / `SLACK_SECURITY_CHANNEL` | Security alerts for org accounts. |
| `CCRC_CALLBACK_URL` | Enables the CC Remote Control login redirect. |
| `TOKEN_SLAYER_*` | Hook version, update pause, quota probing, and rebalance tuning. See `config/token_slayer.php`. |
| `ANTHROPIC_*` | Overrides for the Anthropic OAuth client used by quota probing. |

## Connecting your tools

Open `/setup` after signing in and pick a track:

- **CLI (Claude Code, Codex, Antigravity).** The wizard walks you through
  Python and then gives you one command:
  - macOS / Linux: `curl -fsSL <APP_URL>/install | sh`
  - Windows: `irm <APP_URL>/install.ps1 | iex`

  Check it with `tok status`. Re-running the installer (or `tok update`) is how
  you upgrade. The hook also updates itself on session start when the server
  announces a newer version. `/guide` has the full `tok` command reference.
- **Claude Cowork.** Installs a small Python watcher from `/install-cowork`.
- **claude.ai / Claude Desktop chats.** Install the `/tracker.user.js`
  userscript with Tampermonkey or Violentmonkey. On Chrome 138+, enable
  *Allow user scripts* for Tampermonkey first. The script estimates tokens from
  reply length (~4 chars per token), so its counts are approximate, and it
  reads claude.ai's undocumented endpoints, so it may break when they change.

IDE plugins for VS Code and JetBrains live in `extensions/`.

## Org accounts

Usage is attributed per event to the Claude or Codex account that produced it,
not to the user. Admins manage shared org accounts from `/dashboard`, and
scheduled jobs probe their quotas. See `.ai/domain/accounts.md`.

## Pages

| Path | Description |
| --- | --- |
| `/` | Landing page. Signed-in users go to `/battlefield`. |
| `/battlefield` | Live battle view. Login required. |
| `/history` | Defeated bosses. Login required. |
| `/setup` | Install wizard for every track. Login required. |
| `/update` | Upgrade instructions for an outdated hook. Login required. |
| `/guide` | `tok` command reference and hook customisation. Login required. |
| `/admin/usage` | Usage analytics. Requires the `view_usage_analytics` permission. |
| `/dashboard` | Filament admin panel. Old `/admin/*` URLs redirect here. |

## Scheduled work

`routes/console.php` schedules the idle-fighter sweep, installer artifact
refresh, daily/weekly/monthly/yearly recaps, and account quota probing and
profile sync. In Docker these run in the `scheduler` container
(`php artisan schedule:work`).

## Testing

```bash
spin exec php php artisan test --compact          # PHP (Pest)
spin exec php php artisan test --compact --filter=Name
npx vitest run                                    # JS
```

Browser tests use Pest 4's `visit()` driver and run headless.

## Staging deployment (behind Cloudflare Tunnel)

```bash
cp .env.staging.example .env
# fill in APP_KEY, DB creds, Slack vars, Reverb keys, etc.
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --build
```

The staging stack runs `php`, `reverb`, `scheduler`, and `worker`. Postgres
runs outside the stack and is reached through `host.docker.internal`. A
host-installed `cloudflared` handles public traffic, with both routes on the
same hostname:

- `app.<your-domain>` → `http://localhost:${APP_PORT:-8000}`
- `app.<your-domain>`, path `^/app/` → `http://localhost:${REVERB_HOST_PORT:-8080}` (Reverb)

Both ports are bound to `127.0.0.1`, so cloudflared is the only way in.

## License

MIT
