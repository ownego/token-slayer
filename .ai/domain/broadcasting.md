# Domain: Broadcasting (PHP ↔ JS contract)

Reverb drives the real-time layer. A mismatch anywhere in this contract fails **silently** — no exception, events just never render. Any change to `app/Events/*`, `routes/channels.php`, or Echo listeners requires the `broadcast-reviewer` agent.

## The three-name alignment

Every broadcast event has three names that must match exactly:

| Side | Where | Example |
|---|---|---|
| PHP | `broadcastAs()` in `app/Events/X.php` | `'HitDealt'` |
| Echo layer | `ECHO_EVENT_MAP` key in `resources/js/battlefield/index.js` (Echo listens with a leading dot: `.HitDealt`) | `HitDealt` |
| Scene | `bus.on('…')` key wired in `scene.js` `_busHandlers` | `hit-dealt` (bus key mapped in `ECHO_EVENT_MAP`) |

Renaming any one side without the others is the #1 historical bug. The `scaffold-broadcast-event` skill walks the full checklist for new events.

## Payload rules

- `broadcastWith()` sends scalars in snake_case (`user_id`, `slack_handle`, `avatar_url`, `damage`, `boss_id`, `boss_hp_after`, `boss_max_hp`) — never whole Eloquent models.
- Every key the JS handler reads must exist in `broadcastWith()`; removals/renames must update both sides in the same commit.
- Shape is locked by `tests/Feature/Events/BroadcastShapeTest.php` — new events add a shape test there.

## Channel & queue semantics

- Everything rides the public `battlefield` channel (`new Channel('battlefield')`). Private/presence channels would additionally need `routes/channels.php` authorization.
- Latency-sensitive events (hits, charging, joins) are `ShouldBroadcastNow`. Switching one to queued `ShouldBroadcast` (or adding a queued one) needs a stated reason and a running worker.
- Dispatching from the hook path is always wrapped in `rescue()` — broadcast failure must never fail ingestion.
