# Token Slayer

A cooperative idle boss raid for your team. Each AI token your coding agents
spend (Claude Code, Codex, Antigravity, Claude Cowork) becomes damage against the current boss —
and chats on claude.ai / Claude Desktop can count too via an optional
browser userscript. Watch hits land in real time on the battlefield, defeat
bosses together, and celebrate kills in Slack.

## How it works

1. You sign in with Slack and open the `/setup` wizard, which mints your
   personal hook token.
2. You run the one-line installer it shows; it installs the hooks for your
   agents (re-running it is the upgrade path).
3. Every time your agent finishes a turn, its token usage is posted to the
   server, broadcast over Reverb websockets, and rendered as a hit on the
   live battlefield. Subagents your agent dispatches appear as a small minion
   swarm around your fighter (cosmetic only).
4. When the boss falls, the kill is announced in a Slack channel and a new
   boss spawns.

Admins additionally get a Filament panel at `/dashboard` for the org's shared
Claude/Codex accounts: usage attribution, quota probing, analytics, and
member rebalancing.

## Tech stack

- **Laravel 13** on PHP 8.4+
- **Livewire 4** + **Tailwind CSS 4** for the UI, **Filament 5** (+ Shield) for the admin panel
- **Phaser 3** for the battlefield rendering
- **Laravel Reverb** for websocket broadcasting
- **Redis** (Valkey) for the queue and live subagent presence
- **Laravel Socialite** for Slack OAuth
- **PostgreSQL** in the Docker stacks; `.env.example` defaults to **SQLite**
- **Pest 4** (incl. browser tests) and **Vitest** for testing
- IDE plugins for VS Code and JetBrains under `extensions/`

## Local setup

Contributors run the app in Docker via [Spin](https://serversideup.net/open-source/spin/)
(`docker-compose.yml` + `docker-compose.dev.yml`), which brings up:

| Service | Role |
| --- | --- |
| `php` | The app, on host port `${APP_PORT:-8000}` |
| `reverb` | Websocket server, on host port `${REVERB_PORT:-8080}` |
| `worker` | `queue:work redis` |
| `pgsql` | Postgres 18 |
| `redis` | Valkey 8 — required (queue + subagent presence) |

```bash
cp .env.example .env          # then set REDIS_HOST=redis, DB_* for pgsql, Slack + Reverb vars
spin up
spin exec php composer install
spin exec php php artisan key:generate
spin exec php php artisan migrate
npm install && npm run build
```

Always run PHP through `spin exec php …` — a bare `php` may target a different
container. There is no scheduler container in dev; run
`spin exec php php artisan schedule:work` if you need scheduled commands.

`composer run dev` (outside Docker) runs `artisan serve`, a queue listener,
`pail` logs and Vite together — it does **not** start Reverb or Redis.

### Env vars

| Var | Notes |
| --- | --- |
| `SLACK_OAUTH_CLIENT_ID` / `SLACK_OAUTH_CLIENT_SECRET` | Slack app used for "Sign in with Slack". |
| `SLACK_OAUTH_REDIRECT_URI` | Typically `${APP_URL}/auth/slack/callback`. |
| `SLACK_BOT_TOKEN` | Bot token with `users:read`; resolves members' Slack display names. |
| `SLACK_NOTIFIER_WEBHOOK_URL` | Optional. Incoming webhook for boss-kill announcements and recaps. |
| `SLACK_SECURITY_BOT_TOKEN` / `SLACK_SECURITY_CHANNEL` | Optional. Security alerts when an org account's OAuth token is rejected. |
| `REVERB_*` / `VITE_REVERB_*` | Websocket server (internal) and browser-facing values; `VITE_*` are baked in at build time. |
| `HOOK_NAMESPACE` | Optional. Identifier baked into the installer; defaults to a slug of `APP_NAME`. |
| `GAME_BASE_HP` | Optional. Boss starting HP. Defaults to `1000000`. |
| `GAME_IDLE_MINUTES` | Optional. Minutes of inactivity before a fighter is swept off the battlefield. Defaults to `30`. |
| `GAME_SUBAGENT_IDLE_SECONDS` | Optional. Seconds before a quiet subagent's minion is dropped. Defaults to `60`. |
| `SLAYER_CLI_GITHUB_TOKEN` / `SLAYER_CLI_REPO` | Read-only PAT + repo the server relays the slayer-cli wheel from. |
| `TOKEN_SLAYER_*` | Optional tunables (hook version, update kill switch, probing, rebalance) — see `config/token_slayer.php`. |

## Onboarding a new agent

1. Open `/setup` in a browser and sign in with Slack.
2. Follow the CLI-hooks track: it shows a one-line installer carrying your
   hook token — `curl -fsSL <app>/install | … sh` on macOS/Linux, or the
   PowerShell equivalent against `/install.ps1` on Windows.
3. Run it. Re-running the same command later upgrades the hooks; `/update`
   shows the quick-update command.
4. Open `/battlefield` and watch your hits register as you work.

The same wizard also covers the claude.ai userscript and the Claude Cowork
watcher.

## Tracking claude.ai & Claude Desktop (optional)

Coding agents report exact token counts through hooks, but the claude.ai
web app and Claude Desktop expose no usage data. The tracker userscript
closes that gap with estimates:

1. Install [Tampermonkey](https://www.tampermonkey.net/) (or Violentmonkey)
   in your browser.
2. Open `/setup`, pick the browser-chat track, and click the userscript
   install link (served at `/tracker.user.js`) — your userscript manager
   will prompt you.
3. Open [claude.ai](https://claude.ai) and paste your hook token when the
   script asks (once; it's stored in the userscript manager).

The script estimates tokens from assistant reply length (~4 chars/token)
and posts them as `provider=claude-ai` damage through the same `/api/events`
pipeline as the hooks. Claude Desktop chats sync to your account, so they
are picked up by the script's periodic poll whenever claude.ai is open in
that browser. On first run it baselines your existing history without
dealing damage, so installing won't nuke the boss.

Caveats: counts are estimates (thinking tokens and system overhead are
invisible), dedupe is per-browser, and the script reads claude.ai's
undocumented internal endpoints, so it may need patching when those change.

## Pages

| Path | Description |
| --- | --- |
| `/` | Landing page (redirects to `/battlefield` when signed in). |
| `/battlefield` | Live battle view. Public. |
| `/history` | Defeated bosses. Public. |
| `/setup` | Install wizard (CLI hooks, claude.ai userscript, Cowork watcher). Slack login required. |
| `/profile`, `/guide`, `/update` | Your profile, CLI command reference, and update command. Slack login required. |
| `/dashboard` | Filament admin panel (accounts, users, roles, analytics). Requires a role. |
| `/admin/usage` | Admin usage page. Requires the `view_usage_analytics` permission. |
| `/install`, `/install.ps1` | Hook installers (shell / PowerShell). Public. |
| `/install-cowork`, `/cowork-watcher.py` | Claude Cowork watcher installer. Public. |
| `/tracker.user.js` | The claude.ai tracker userscript. Public. |

## Testing

```bash
spin exec php php artisan test --compact     # Pest: tests/Unit, tests/Feature, tests/Browser
npx vitest run                               # Vitest: tests/js (battlefield logic)
```

Browser tests use Pest 4's `visit()` driver and run headless by default.
Contributor conventions (TDD, docblocks, architecture) live in `CLAUDE.md`
and `.ai/guidelines/`.

## Docker deployment

Ships with a multi-stage `Dockerfile` (adapted from the `serversideup/php`
image) and three layered compose files.

### Staging on a home server (behind Cloudflare Tunnel)

The staging stack runs `php`, `reverb`, `scheduler` (`schedule:work`) and
`worker` (`queue:work redis`). Postgres and Redis are expected to run
externally (reachable from the containers via `host.docker.internal`), and a
host-installed `cloudflared` handles public ingress. Redis is required — the
worker and the battlefield's subagent presence use it — even though the
compose file has no Redis service and `.env.staging.example` still says
otherwise.

```bash
cp .env.staging.example .env
# fill in APP_KEY, DB creds, REDIS_*, Slack vars, Reverb keys, etc.
docker compose -f docker-compose.yml -f docker-compose.staging.yml up -d --build
```

In the Cloudflare Zero Trust dashboard, add two public hostname routes on
the same hostname:

- `app.<your-domain>`, path `^/app/` → `http://localhost:${REVERB_HOST_PORT}` (Reverb)
- `app.<your-domain>`, no path → `http://localhost:${APP_PORT}` (Laravel)

Both ports are bound to `127.0.0.1` on the host, so the only inbound path
is via cloudflared.

## License

MIT
