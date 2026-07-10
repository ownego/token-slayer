---
name: scaffold-broadcast-event
description: Use when adding or renaming a real-time broadcast event — walks the full PHP→Echo→scene chain so all three names stay aligned and the shape test exists.
---

# Scaffolding a broadcast event

A broadcast event spans four files. Missing any one fails silently in production. Work through ALL steps in order.

## Steps

1. **PHP event** — `app/Events/<Name>.php`:
   - `ShouldBroadcastNow` for latency-sensitive gameplay events (hits, charging, joins); queued `ShouldBroadcast` only with a stated reason.
   - `broadcastOn(): array` → `[new Channel('battlefield')]`
   - `broadcastAs(): string` → PascalCase name, e.g. `'FighterStunned'`
   - `broadcastWith(): array` → snake_case scalars only, never models. Resolve user display fields the way sibling events do.
2. **Echo layer** — `resources/js/battlefield/index.js`: add the `ECHO_EVENT_MAP` entry mapping the `broadcastAs` name to a kebab-case bus key (`FighterStunned` → `fighter-stunned`).
3. **Scene handler** — `resources/js/battlefield/scene.js`: register the bus key in `_busHandlers`; the handler must be released on `shutdown` like its siblings.
4. **Shape test** — add a case to `tests/Feature/Events/BroadcastShapeTest.php` locking channel, `broadcastAs`, and `broadcastWith` keys. Write it FIRST (tdd skill).
5. **Dispatch site** — dispatch wrapped in `rescue()` if fired from the ingestion path.

## Conventions checklist (must all hold)

- [ ] `broadcastAs()` string === `ECHO_EVENT_MAP` key (Echo subscribes with a leading `.`)
- [ ] Bus key registered in `scene.js` and torn down on shutdown
- [ ] Payload keys snake_case, scalar, and every key the JS reads exists in `broadcastWith()`
- [ ] BroadcastShapeTest case added and was seen failing first
- [ ] `rescue()` around dispatch on the hook path
- [ ] Ran `spin exec php php artisan test --compact --filter=BroadcastShape` + `npm run build`

Then request review from the `broadcast-reviewer` agent.
