# Domain: Broadcasting (PHP ↔ JS contract)

Reverb drives the real-time layer. A mismatch anywhere in this contract fails **silently**: there is no exception, and the events just never render. Any change to `app/Events/*`, `routes/channels.php`, or an Echo listener requires the `broadcast-reviewer` agent. New events: follow the `scaffold-broadcast-event` skill.

## The three-name alignment

Every broadcast event has three names that must match exactly:

| Side | Where | Example |
|---|---|---|
| PHP | `broadcastAs()` in `app/Events/X.php` | `'HitDealt'` |
| Echo layer | `ECHO_EVENT_MAP` key in `resources/js/battlefield/index.js` (Echo listens with a leading dot: `.HitDealt`) | `HitDealt` |
| Scene bus | the map's value, a `BusEvent` constant in `resources/js/battlefield/constants.js`, handled in `scene.js` `_busHandlers` | `BusEvent.HIT` = `'hit'` → `this.fighter.handleHit` |

Renaming one side without the others is the #1 historical bug. The bus key is **not** derived from the PHP name (`HitDealt` → `'hit'`, `FighterCharacterChanged` → `'character-changed'`), so always go through the `BusEvent` constant.

## Event catalog (all verified aligned 2026-09-26)

All events are `ShouldBroadcastNow` on the public `battlefield` channel. Handlers are methods on the scene's managers (`this.fighter` = `fighter.js`, `this.boss` = `boss.js`, `this.charge` = `charge.js`, `this.minions` = `minions.js`, all in `resources/js/battlefield/`; see `battlefield.md`).

| PHP class = `broadcastAs` | Dispatched from | Payload keys | `BusEvent` → handler |
|---|---|---|---|
| `HitDealt` | `EventController` (Stop, tokens > 0) | `user_id, slack_handle, avatar_url, damage, boss_id, boss_hp_after, boss_max_hp, model, flair, flair_duration_ms, flair_color` | `HIT` `'hit'` → `fighter.handleHit` |
| `BossKilled` | `EventController`, once per boss killed in the chain | `boss_number, boss_name, boss_id, killer_user_id, killer_slack_handle, killer_avatar_url` | `BOSS_KILLED` `'boss-killed'` → `boss.handleBossKilled` |
| `BossSpawned` | `EventController`, once after any kill | `boss_id, boss_number, boss_name, max_hp, fighters[] {user_id, character}` | `BOSS_SPAWNED` `'boss-spawned'` → `boss.handleBossSpawned` |
| `FighterJoined` | `EventController` (SessionStart) | `user_id, slack_handle, display_name, avatar_url, character, position` | `FIGHTER_JOINED` `'fighter-joined'` → `fighter.handleFighterJoined` |
| `FighterCharging` | `EventController` (prompt / pre-invocation / pre-tool-use; post-hit label for Stop-only providers) | `user_id, slack_handle, avatar_url, character, activity, position` | `FIGHTER_CHARGING` `'fighter-charging'` → `charge.handleCharging` |
| `FighterChargeCleared` | `EventController` (Stop with 0 tokens) | `user_id` | `FIGHTER_CHARGE_CLEARED` `'fighter-charge-cleared'` → `charge.handleChargeCleared` |
| `FighterIdled` | `fighters:sweep-idle` command (every minute, users past `game.idle_minutes`) | `user_id` | `FIGHTER_IDLED` `'fighter-idled'` → `fighter.handleIdled` + `minions.despawnAll` |
| `FighterMoved` | `Livewire\Battlefield::move` (user drags own fighter) | `user_id, x, y` | `FIGHTER_MOVED` `'fighter-moved'` → `fighter.handleFighterMoved` |
| `FighterCharacterChanged` | `Livewire\CharacterSelect` | `user_id, character` | `CHARACTER_CHANGED` `'character-changed'` → `fighter.updateCharacters([payload])` |
| `FighterAgentCountChanged` | `EventController` (subagent dispatch/activity), `fighters:sweep-idle` | `user_id, count, seq` | `FIGHTER_AGENT_COUNT_CHANGED` `'fighter-agent-count-changed'` → `minions.handleAgentCountChanged` |
| `FighterAgentToolUsed` | `EventController` (pre/post-tool-use with `agent_id`, hook v7) | `user_id, agent_id, busy` | `FIGHTER_AGENT_TOOL_USED` `'fighter-agent-tool-used'` → `minions.handleAgentToolUsed` |

Not broadcast: `AccountTokenRejected` is a plain server-side event (listener `SendReauthAlert` → Slack). `BusEvent.POSITIONS_RESYNCED` is client-only: it is emitted from a Livewire `battlefield-resynced` dispatch after an Echo reconnect, never from Reverb.

**Second consumer: `resources/js/ide-bridge.js`** (loaded by `layouts/app.blade.php` only in IDE-embed mode, for the VS Code and JetBrains extensions) listens directly to `.HitDealt`, `.BossKilled`, `.BossSpawned`, `.FighterCharging`, and `.FighterIdled`, and reads `user_id`, `damage`, `boss_id`, `boss_hp_after`, `boss_max_hp` (via `packHit()` in `ide-bridge-internal.js`), `killer_user_id`, `killer_slack_handle`, `boss_name`, `max_hp`, and `activity`. It bypasses `ECHO_EVENT_MAP`, so renaming or removing those names or keys must update it too.

## Payload rules

- `broadcastWith()` sends scalars in snake_case. Never send whole Eloquent models. The one nested value is `BossSpawned.fighters`, a list of scalar pairs.
- Every key a JS handler reads must exist in `broadcastWith()`. Removals and renames must update both sides (and `ide-bridge.js`) in the same commit.
- User display fields: `displayHandle()` for `slack_handle`. `avatar_url` is the raw Slack URL everywhere **except** `FighterJoined`, which sends `route('avatar', $user)` (the `/avatars/{user}` CORS proxy).
- `character` comes from `$user->characterForBoss($bossId)`. `position` comes from `FighterPositionCache::get()` (null when the user has never moved).
- Shape is locked by `tests/Feature/Events/BroadcastShapeTest.php`. It includes one catch-all test asserting that every battlefield event is `ShouldBroadcastNow` on `battlefield` with a short `broadcastAs`, plus one test asserting `HitDealt`'s values are all scalars. New events add a case there.
- `HitDealt` also carries `model`, `flair`, `flair_duration_ms` and `flair_color`, all **nullable**, because it is dispatched for cowork/claude-ai Stops where no model exists. The flair decision is **server-side** (`ModelFlairResolver`, reading the admin-curated `ai_models` table, matched by exact raw id, cached 60 s). `flair` is keyed on the model FAMILY, so the JS never learns which model ids are special, and enabling one is an admin toggle with no rebuild.
- **`flair_duration_ms` has a client-side fallback.** `flair.js`'s `resolveFlairDuration(payloadDurationMs, TIMINGS.flairDurationMs)` falls back to `resources/js/battlefield/config/timings.js` when the value is missing or non-positive. A payload change here therefore does not require every client to be current.
- **The flair badge is deliberately absent from `snapshotState()`.** It is a several-second effect; losing it on rotate is cheaper than threading timers through the snapshot. Its lifetime is owned by its own timer, never by the next hit.
- `FighterAgentCountChanged.seq` is monotonic per user. The client drops any payload whose `seq` is older than one already applied, because Reverb gives no delivery-order guarantee.

## Channel & queue semantics

- Everything rides the public `battlefield` channel (`new Channel('battlefield')`). `routes/channels.php` only defines the default `App.Models.User.{id}` private channel, which nothing uses. A private or presence channel would need an authorization callback there.
- All eleven events are `ShouldBroadcastNow`. Switching one to queued `ShouldBroadcast` needs a stated reason and a running worker.
- Queued **listeners** exist and need a worker: `AnnounceBossKill` (`BossKilled` → Slack webhook) implements `ShouldQueue`.
- Dispatching from the hook path always goes through `EventController::dispatchSafely()` (`rescue()`): a broadcast failure must never fail ingestion. Commands and Livewire actions call `event()` / `::dispatch()` directly.
- Reverb has no replay. A client that missed events while disconnected recovers through the Livewire `resync()` call triggered on reconnect (`bindResyncOnReconnect()` in `index.js`), and through the scene reboot payload (`data-battlefield-state`).
