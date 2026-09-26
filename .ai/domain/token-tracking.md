# Domain: Token Tracking (hook → event → damage pipeline)

Related: `accounts.md` (who the usage is attributed to), `broadcasting.md` (every event fired below), `battlefield.md` (how the client renders it).

## Life of a hook event (end to end)

1. **Developer machine.** Claude Code / Codex / Antigravity fires a hook → `~/.config/{namespace}/send-hook.sh` (rendered from `resources/views/install-script.blade.php`, or its PowerShell twin). For `Stop`/`SubagentStop` only, it reads the transcript locally and adds `tokens`, `models`, `input_tokens`, `cache_*` to the body. It sources the user's `custom.sh`, then filters the body to the whitelist below and `POST`s it (3 s curl timeout, backgrounded — the hook never blocks a session).
2. **`POST /api/events?provider=…`** → `hook.token` middleware (`AuthenticateHookToken`): `sha256(bearer)` must equal `users.hook_token` (only the hash is stored — `HookTokenRotator`). The user is exposed as `$request->user('hook')`.
3. **`EventController@store`** (`app/Http/Controllers/Api/EventController.php`), in order:
   1. `AccountResolver::resolve(account_org_id, account_email, provider)` → `?int $accountId` (see `accounts.md`).
   2. `hook_event_name` → kebab-case (`PreToolUse` → `pre-tool-use`).
   3. `resolveStopUsage()` → `TurnUsage` (Stop/SubagentStop only); `ModelUsageParser::primaryModel()` picks `events.model`.
   4. Stamps `users.last_event_at`, `client_version`, `hook_version`.
   5. Per-type branch (table below) — charging cache + broadcasts, subagent presence.
   6. Stop/SubagentStop with `tokens > 0`: `Event::create` (append-only), `AccountMembershipRecorder::record()` (best-effort), `DamageService::apply()`, then `BossKilled`×n → `BossSpawned` → `HitDealt` (+ flair from `ModelFlairResolver`).
   7. Responds `201` with the **update signal**: `hook_version`, `install_sha256`, `install_ps1_sha256`, `wheel_sha256`, `paused`. The hook saves that body to `~/.config/{namespace}/update-state`; on `SessionStart` it runs `token-slayer update --if-newer` (skipped when `SLAYER_NO_AUTO_UPDATE` is set). No separate update endpoint exists.
4. Every broadcast goes through `dispatchSafely()` → `rescue(fn () => event($e))`: a downed Reverb must never 500 the hook.

## Providers (`?provider=` query param, stored in `events.provider`)

| Value | Client | Hook events sent | Notes |
|---|---|---|---|
| `claude-code` (default) | Claude Code hook (`/install`, `/install.ps1`) | `SessionStart`, `UserPromptSubmit`, `PreToolUse`, `PostToolUse` (v7+), `Stop`, `SubagentStop` | `SessionEnd`/`Notification` would fall through to a bare 201; not registered. |
| `codex` | Same installer, `~/.codex/hooks.json` | `SessionStart`, `Stop`, `SubagentStop` | Own token extractor (below). Account match by `chatgpt_account_id`. |
| `antigravity` | Same installer, `~/.gemini/config/hooks.json` | `SessionStart`, `PreInvocation`, `PreToolUse`, `Stop` | Explicitly drops `PostToolUse` (`ns_data.pop`) — payload shape unverified. Uses the Claude-shaped extractor. |
| `cowork` | `/install-cowork` + `/cowork-watcher.py` (Python watcher) | `Stop` only | Server re-sets a persistent `cowork` charging label after each hit. |
| `claude-ai` | Userscript `/tracker.user.js` | `Stop` only | Estimated usage; label `claude.ai`. |

`EventController::providerActivityLabel()` owns the Stop-only labels. Any provider other than `codex` resolves accounts against **Claude** accounts.

## Per-type behavior in `EventController`

| Normalized type | Charging cache / broadcasts | Subagent presence (`SubagentCountCache`) | Writes `events`? |
|---|---|---|---|
| `user-prompt-submit`, `pre-invocation` | put `custom_activity ?? 'thinking…'` → `FighterCharging` | — | no |
| `pre-tool-use` | put `custom_activity ?? summarizeToolUse()` → `FighterCharging` | `tool_name` ∈ {`Task`,`Agent`} → `recordDispatch()` + `FighterAgentCountChanged`. Has `agent_id` → `recordActivity()` (+ count broadcast only if it changed) + `FighterAgentToolUsed{busy:true}` | no |
| `post-tool-use` | — | Has `agent_id` → `recordActivity()` + `FighterAgentToolUsed{busy:false}`; otherwise no-op | no |
| `session-start` | `FighterJoined` | — | no |
| `subagent-stop` | as `stop` | `resolveAgentId()` → `recordActivity()` (never a decrement) | yes, if tokens > 0 **and** `hook_version ≥ 5` |
| `stop` | tokens > 0: hit broadcasts, then `FighterCharging` (Stop-only providers) or forget the cache. tokens = 0: forget + `FighterChargeCleared` | deliberately untouched | yes, if tokens > 0 |

- Activity labels are truncated to 40 chars. `summarizeToolUse()` only ever surfaces the tool name (`mcp__server__tool` → `MCP: server`) — never command text, paths or URLs, because the bubble is public.
- `FighterIdled` is **not** sent from ingestion; only `fighters:sweep-idle` sends it (after `game.idle_minutes`, default 30). A zero-token Stop sends `FighterChargeCleared`, because `FighterIdled` removes the fighter client-side.

## Payload whitelist (hook side)

**Fixed sixteen fields**, applied unconditionally by jq: `hook_event_name`, `session_id`, `tokens`, `models`, `tool_name`, `custom_activity`, `client_version`, `hook_version`, `account_email`, `account_uuid`, `account_source`, `account_org_id`, `input_tokens`, `cache_creation_input_tokens`, `cache_read_input_tokens`, `agent_id` (v7). Nulls are stripped (`with_entries(select(.value != null))`), so any field may be absent. The prompt, `tool_input`, `tool_response`, the last assistant message, `cwd`, `permission_mode` and `transcript_path` never leave the machine. (The filter used to be opt-in behind `SLAYER_MINIMAL_PAYLOAD`; that flag is gone.)

**Ordering is load-bearing:** the filter runs *after* `custom.sh` is sourced. `custom.sh` shares the shell, so it still sees the full body. The `custom_activity` recipes on the guide page read `tool_input` locally, and only the label they build is sent. `account_uuid` is sent but nothing reads it. It is kept on purpose.

When changing the registered-event list, the re-registration loop must strip our fingerprint from **every** key already in `~/.claude/settings.json` before re-adding. It once iterated only the events still in its own list, so shrinking the list would have left stale registrations firing silently.

## Hook versions (`config('token_slayer.hook_version')`, currently **7**)

| Version | Date | What changed | Server dependency |
|---|---|---|---|
| < 5 | — | Sends no `hook_version` (only `client_version`) | `HookVersionStatus` treats "client_version set, hook_version null" as outdated |
| 5 | 2026-09-04 | Hook versioned from this repo; `SubagentStop` reads the subagent's **own** transcript (`agent_transcript_path`) | `EventController::SUBAGENT_TOKENS_MIN_HOOK_VERSION = 5` — lower versions' SubagentStop tokens are discarded |
| 6 | 2026-09-11 | Transcript parsed only on Stop/SubagentStop; `transcript_path` no longer sent; adds `input_tokens`/`cache_creation_input_tokens`/`cache_read_input_tokens` (stored, never added to damage) | `TurnUsage::fromPayload` |
| 7 | 2026-09-24 | `agent_id` added to whitelist; `PostToolUse` registered again | `resolveAgentId()`, `FighterAgentToolUsed` |

`client_version` (the CLI wheel's release tag, from a repo this project does not publish) and `hook_version` (owned here) are **independent**. A hook-only change bumps `hook_version` only. Both are written to `~/.config/{namespace}/version` and `hook-version`, and both are sent on every event. The outdated-hook nudge (`HookVersionStatus`) shows on the battlefield, the profile page and the Filament topbar.

## Token resolution for Stop events

**The hook owns token extraction; the server never opens a transcript.** The hook computes the token total and a `{model: tokens}` map on the machine that owns the file, and sends both inline. There is no server-side fallback. `TranscriptReader` and its retry loop were removed.

**Two extractors, dispatched by provider.** Using one Claude-shaped walk for every provider is why Codex ingestion was silently dead from 2026-06-28 to 2026-09-04. That walk returns `0` on every Codex rollout, and `EventController` answers `201` with no row, so nothing surfaced.

- **Claude-shaped** (`claude-code`, and `antigravity`, which is unverified and left untouched): walk backwards accumulating `assistant` / `PLANNER_RESPONSE` / `source == "MODEL"` entries, stopping at the first `user` entry that is not a `tool_result` wrapper. The model lives at `message.model`, **not** at a top-level `model` key (0 of 75,811 assistant entries had one). The bare `$e.model` branch is a fallback for the unverified non-Claude shapes only.
- **Codex**: rollout JSONL shares no shape with a Claude transcript. Sum `event_msg.payload.info.last_token_usage.output_tokens`, stop at `task_started`, and take the model from the first `turn_context` seen walking back. Three traps: `total_token_usage` is cumulative for the whole session; `total_tokens` includes input and cached input (~30× output); `reasoning_output_tokens` is a **subset** of `output_tokens`, so adding it double-counts.

**The capture and the merge must change together.** The jq emits an object (`{tokens, models}`). Merged with the old scalar `. + {tokens:$t}`, it would nest as `{"tokens":{"tokens":478,…}}`. PHP's `(int)` cast on an array yields `1` with no warning, so every event would deal 1 token into the append-only ledger.

**`models` is attacker-controllable** (a hook token is all that guards it). `ModelUsageParser::sanitize()` validates it before anything reaches the DB. A turn is recorded under one `events.model`: the most expensive family it touched (`ModelFamily::rank()`: fable 40 > opus 30 > gpt 25 > sonnet 20 > haiku 10), then the highest token count within that family. Ranking by token count alone would be wrong: in a limit-fallback turn the cheap model produces more tokens because it finished the work. Multi-model turns were measured at 2 in 4,524 on one machine.

Very short turns can leave only placeholder usage in the transcript. The hook reads it faithfully, and the resulting undercount is accepted (decided 2026-09-06). Don't re-propose a retry.

## Subagent presence (cosmetic, never damage)

`SubagentCountCache` (`app/Services/SubagentCountCache.php`) tracks how many dispatched subagents each user has running. This drives the minion swarm (`resources/js/battlefield/minions.js`, see `battlefield.md`) and never HP.

- **Storage:** one Redis key per subagent, `subagent:presence:{userId}:{agentId}`, or `…:pending:{random}` before its `agent_id` is known. Each key has TTL = `config('game.subagent_idle_seconds')` (default **60**, raised from 30 on 2026-09-25 because a single >30 s tool call dropped a live subagent). Refreshing activity is one `EXPIRE`. A quiet subagent needs no cleanup, because Redis expires it.
- **`recordDispatch()`** (parent's `PreToolUse` with `Task`/`Agent`): creates a pending key. `Agent` is what Claude Agent SDK harnesses report instead of `Task` (verified 2026-09-22).
- **`recordActivity($userId, $agentId)`** (any event with an `agent_id`): refresh own key → else claim the oldest pending key → else create a fresh key. A subagent that re-emerges after expiry simply re-summons. Returns `null` when the count didn't change, so no redundant broadcast is sent.
- **`resolveAgentId()`** prefers the top-level `agent_id` (v7). It falls back to the `parent_session_id:agent_id` form that older hooks fold into a SubagentStop's `session_id`.
- **Why not a counter or a table:** one subagent can fire several `SubagentStop`s, because the harness ends a turn when it backgrounds one of its own tool calls. A decrementing counter therefore drained live swarms to zero (verified 2026-09-23). A DB table with `last_seen_at` then cost one UPDATE per tool call once v7 added the heartbeat. Redis TTL replaced both (2026-09-24).
- **Parent `stop` never touches presence.** With SDK harnesses, a subagent can outlive several parent turns. Resetting on Stop wiped real swarms (verified 2026-09-22).
- **`fighters:sweep-idle`** (every minute, `routes/console.php`) also calls `sweepAllStale()`. TTL deletes keys silently, and this sweep is what tells open browsers to shrink. It finds candidate users in the permanent Redis SET `subagent:known-users`, not by scanning presence keys: a user whose last key expired has no key left to find. It broadcasts only when the live count differs from the Cache-backed `subagent-count:last-broadcast:{userId}`.
- **Fail-soft:** every Redis call goes through `safely()` (log + default, never throw). A Redis outage degrades minions and nothing else. Tests use `fakeRedis()` from `tests/Pest.php`, because no real Redis runs in the test environment.
- Every change broadcasts `FighterAgentCountChanged {user_id, count, seq}`. `seq` is a Cache-backed monotonic per-user counter the client uses to drop out-of-order broadcasts.

## After a hit

`DamageService::apply(user, tokens)` locks the alive boss row (`BossArena::lockedCurrent()`) inside a transaction. Damage beyond the boss's remaining HP rolls over into the next boss (`BossArena::spawnNext()`). It returns `DamageResult{boss, killedBosses}`, and the controller broadcasts from that. `BossKilled` also has a queued listener, `AnnounceBossKill`, which posts to Slack via `services.slack_notifier.webhook_url`.

## Aggregation & caches

| Service | What | TTL / invalidation |
|---|---|---|
| `DamageTotals` | global / per-user / per-account token sums | 60 s on `CacheKeys::DAMAGE_TOTALS`; `FleetUsageRefresher` busts it |
| `FighterChargingCache` | current charging label per user (for boot payload) | `put` on activity, `forget` on hit/clear |
| `FighterPositionCache` | last dragged position (`FighterMoved`) — despite the name, DB-backed (`fighter_positions` table) | written by `Livewire\Battlefield::move` |
| `ModelFlairResolver` | enabled flair models from `ai_models` | 60 s |
| `AccountMembershipRecorder` | known member ids per account | 1 h; flushed by `Account` model events |

All aggregates derive from `events`. There are no mutable usage counters. `GET /api/state` (unauthenticated) returns the alive boss, the fighters active within `idle_minutes`, and the last 10 events.

## Install scripts

Served from `routes/web.php`:

| Route | View | Notes |
|---|---|---|
| `/install` | `install-script.blade.php` (POSIX) | Claude Code + Codex + Antigravity hooks, jq, CLI venv |
| `/install.ps1` | `install-script-ps1.blade.php` | Native Windows; lockstep with POSIX |
| `/install-cowork`, `/cowork-watcher.py` | `cowork-install-script`, `cowork-watcher` | `?provider=cowork` baked in |
| `/tracker.user.js` | `userscript.blade.php` | `?provider=claude-ai` baked in |
| `/dist/slayer_cli-latest.whl` | `SlayerWheelController` | `hook.token` protected |

- `/install` and `/install.ps1` are served from `ReleaseArtifacts`, which renders each script once and caches the bytes **together with** their sha256. The served bytes and the published digest therefore cannot drift. `client-artifacts:refresh` (every 5 min) keeps that cache warm, so ingest never waits on GitHub (8 s timeout versus the client's 3 s). The routes return 503 when the cache is empty.
- **Lockstep POSIX ↔ PS1** is enforced by tests that run against **both** routes (`InstallScriptTest`, `InstallScriptPs1Test`, `HookSnippetTest`). A whole-script `toContain` is not enough, because field names also appear in comments and in the lines that *remove* stale registrations. The rendered hook is also parsed with `sh -n`: the hook is fire-and-forget, so a syntax error would only show up as missing events.
- **Idempotent:** Claude hooks are **assigned** per event key in `~/.claude/settings.json` (fingerprint-matched, not appended). Codex hooks are merged into `~/.codex/hooks.json`. A legacy `# >>> {ns} hooks` block in `~/.codex/config.toml` is removed, because it collides with Codex's own `[hooks.state]` table and breaks config.toml. Re-running the install URL is the upgrade path. The hook token is read at runtime from `~/.config/{namespace}/token`.
- The installer also registers two CLI hooks: `slayer_cli hook usage-refresh` (Stop) and `hook session-track-start` (SessionStart). It invokes the venv Python directly with `SLAYER_NS`, never the shared shim, so a multi-namespace machine updates the right install.
- **jq is always the installer's own pinned binary.** Every install downloads a version-pinned, SHA256-verified `jq` into `~/.config/{namespace}/bin/` and re-downloads it on a checksum mismatch. The hook resolves it once as `$JQ`, and every call site guards with `[ -x "$JQ" ]`. Never use `[ -n "$JQ" ]` or a system `jq`: system-jq version drift silently corrupted attribution on prod. Keep the `custom.sh` examples on the guide page (`resources/views/guide.blade.php`) on the same convention.
- **Fail-fast, all-or-nothing.** Every setup step exits immediately with the real captured error (curl/pip stderr). There is no rollback; recovery is re-running the URL.
- `token-slayer setup` (CLI) pulls admin-provisioned grants and confirms them via `POST /api/provisioned/confirm`. See *Provisioning* in `accounts.md`.

## Recipes

**Bump the hook version** (any change to the hook template that clients must pick up):
1. Write the failing test first in `InstallScriptTest` **and** `InstallScriptPs1Test` (or `HookSnippetTest`).
2. Edit both templates in lockstep.
3. Bump the default in `config/token_slayer.php` (`hook_version`). If the server must behave differently for older hooks, gate on the **per-request** `payload.hook_version`, never on `users.hook_version`: one developer can run two machines on different versions (see `SUBAGENT_TOKENS_MIN_HOOK_VERSION`).
4. Add a row to the hook-versions table above.
5. Clients self-update on their next `SessionStart`. `TOKEN_SLAYER_UPDATES_PAUSED=true` halts the whole fleet.

**Add a hook event type:** register it in the installer's event list (both scripts, and check the fingerprint-strip loop), add a branch in `EventController@store`, and add cases to `tests/Feature/Api/EventIngestionTest.php`. If it fires often, keep the branch cheap: no DB write per call. That cost is why presence moved to Redis.

**Add a provider:**
1. Pick the `?provider=` value and bake it into its install script/route.
2. If its transcript isn't Claude-shaped, add a dedicated extractor in the hook. Never reuse the Claude walk (see the Codex outage above).
3. If it only emits Stop, add a label in `providerActivityLabel()`.
4. If it has its own accounts, extend `AccountResolver::resolve()` and the `App\Enums\Provider` enum (see `accounts.md`).
5. Add ingestion tests with that `?provider=`.

**Add a new broadcast from ingestion:** follow the `scaffold-broadcast-event` skill, and dispatch via `dispatchSafely()`.
