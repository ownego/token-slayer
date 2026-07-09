---
name: battlefield
description: Use when working in resources/js/battlefield/ — the Phaser 3 real-time battlefield game (scene, snapshot, leaderboard, projectile, attacks, layout, bus) or wiring a Laravel broadcast event to it. Covers the Echo→bus→scene data flow, the event-name/payload contract, snapshot round-trip, and Phaser teardown rules.
---

# Battlefield (Phaser game)

The battlefield is a Phaser 3 scene that renders live combat driven by Reverb broadcast events. It lives in `resources/js/battlefield/` and is booted by `window.bootBattlefield(mount, state)` from a Blade view carrying a `data-battlefield-state` payload.

## Data flow (the one thing to get right)

```
PHP Event  ──broadcastAs()──▶  Reverb 'battlefield' channel
   │                                    │
   │                          index.js ECHO_EVENT_MAP  (PHP name → bus key)
   │                                    ▼
broadcastWith() payload ───────▶  bus.emit(key, payload)
                                        ▼
                          scene.js _busHandlers[key] → handleX(payload)
```

Three names must line up for an event to render. Change one, change all three:

| PHP `broadcastAs()` | index.js `ECHO_EVENT_MAP` key | scene.js bus key |
|---|---|---|
| `HitDealt` | `HitDealt: 'hit'` | `hit` |
| `BossSpawned` | `BossSpawned: 'boss-spawned'` | `boss-spawned` |
| `BossKilled` | `BossKilled: 'boss-killed'` | `boss-killed` |
| `FighterJoined` | `FighterJoined: 'fighter-joined'` | `fighter-joined` |
| `FighterCharging` | `FighterCharging: 'fighter-charging'` | `fighter-charging` |
| `FighterIdled` | `FighterIdled: 'fighter-idled'` | `fighter-idled` |

Echo listens with a leading dot (`.HitDealt`) because events use a custom `broadcastAs()` name. The **payload** the handler reads is exactly `broadcastWith()` — snake_case keys (`user_id`, `slack_handle`, `avatar_url`, `damage`, `boss_id`, `boss_hp_after`, `boss_max_hp`). Add a client-side read → add the key in `broadcastWith()`.

## Adding a new broadcast event

1. Create `App\Events\YourEvent` (`ShouldBroadcastNow` for latency-sensitive combat), on `new Channel('battlefield')`, with `broadcastAs()` + `broadcastWith()`.
2. Add `YourEvent: 'your-key'` to `ECHO_EVENT_MAP` in `index.js`.
3. Add `'your-key': p => this.handleYourEvent(p)` to `_busHandlers` in `scene.js` and write the handler.
4. Add the payload assertion to `tests/Feature/Events/BroadcastShapeTest.php`.

## Snapshot round-trip

`snapshotState(currentState, scene)` serializes the live scene so `bootBattlefield` can `scene.restart()` on orientation/mode flips without losing state. **Its output must match the shape of the initial `data-battlefield-state` payload** (`boss`, `leaderboard`, `fighters`). Any field you add to boot state must also be captured in `snapshot.js`, or it resets on rotate.

## Phaser teardown rules

- Anything `scene.add.*`/tween/timer/`bus.on` you create must be released on `shutdown`. The scene already does `bus.off(evt, fn)` for `_busHandlers` and `leaderboard.destroy()` in its `shutdown` handler — extend that block, don't add listeners that escape it.
- Fighters/charges are keyed by `user_id`. Keep key types consistent (the payload id is numeric) or lookups silently miss.
- Boot/resize listeners are cleaned up via the module-level `_cleanupResize`; the game's `destroy` event calls it. Don't add `window` listeners outside that pattern.

## Verify changes

- JS unit tests: `npm test` (Vitest).
- Broadcast shape: `spin exec php php artisan test --filter=BroadcastShape`.
- Visual: boot the page and watch the browser console (chrome-devtools MCP) for `[battlefield]` warnings — an Echo-not-available warning means events never arrive.
