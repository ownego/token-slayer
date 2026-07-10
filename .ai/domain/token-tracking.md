# Domain: Token Tracking (hook → event → damage pipeline)

## Ingestion

`POST /api/events` (`EventController@store`), authenticated by `hook.token` middleware — `Authorization: Bearer <users.hook_token>` identifies the **user**. Provider comes from the `?provider=` query param baked into each install script: `claude-code` (default), `codex`, `cowork`, `claude-ai`.

Hook event names arrive as `hook_event_name` (e.g. `Stop`, `PreToolUse`) and are normalized to kebab-case. Behavior per type:

- `user-prompt-submit` / `pre-invocation` / `pre-tool-use` → charging bubble broadcasts (`FighterCharging`), activity summarized from tool payload.
- `session-start` → `FighterJoined`.
- `stop` → the only type that creates an `Event` row, and only when resolved tokens > 0.

## Token resolution for Stop events

1. Inline `tokens` field in the payload wins — the hook helper pre-computes it client-side by summing `output_tokens` across the latest turn of the local transcript JSONL (jq). This is what makes cross-machine deployments work.
2. Fallback: server reads `transcript_path` via `TranscriptReader` (same-machine deployments only), retrying 3× / 100 ms because the transcript may still be flushing when Stop fires.
3. Zero tokens → no Event row; fighter gets `FighterIdled` so the charge visual clears.

## After a hit

`DamageService::apply(user, tokens)` mutates boss HP transactionally, returns killed bosses + the (possibly new) live boss. Controller then broadcasts `BossKilled`/`BossSpawned`/`HitDealt`. Broadcasts are best-effort (`rescue()`) — a downed websocket must never 500 the hook.

## Aggregation

`DamageTotals` — global/per-user/per-account sums over rolling windows (hourly/daily/monthly), 60 s cache on the global key. All aggregates derive from `events`; there are no mutable counters.

## Install scripts

Blade-rendered shell scripts served from web routes (`/install`, `/install-cowork`, `/tracker.user.js`). Idempotent by design: Claude hooks are **assigned** per event key in `~/.claude/settings.json` (not appended); the Codex `config.toml` block is marker-delimited and replaced. Re-running the install URL is the upgrade path. Hook token is read at runtime from `~/.config/{namespace}/token`.
