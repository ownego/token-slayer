---
name: scaffold-broadcast-event
description: Use when adding or renaming a real-time broadcast event — walks the full PHP→Echo→scene chain so all three names stay aligned and the shape test exists.
---

# Scaffolding a broadcast event

A broadcast event spans five files. If any one is missing, it fails silently in production. The contract and the full catalog of existing events live in `.ai/domain/broadcasting.md`; read it first. Work through ALL steps in order.

## Steps

1. **Shape test first** (tdd skill): in `tests/Feature/Events/BroadcastShapeTest.php`, add:
   - a `test('<Name> broadcasts on the battlefield channel with expected payload', …)` locking `broadcastOn()[0]->name === 'battlefield'`, `broadcastAs()`, and the exact `broadcastWith()` keys;
   - the event to the `$events` map in the catch-all test *"every battlefield event broadcasts now…"*.
   Run `--filter=BroadcastShape` and watch it fail (class not found is the right RED here).
2. **PHP event**: `app/Events/<Name>.php`, copied from a sibling such as `FighterAgentToolUsed.php`:
   - `implements ShouldBroadcastNow`, `use Dispatchable, SerializesModels`. Use queued `ShouldBroadcast` only with a stated reason, since it needs a worker.
   - `broadcastOn(): array` → `[new Channel('battlefield')]`
   - `broadcastAs(): string` → the PascalCase class name, e.g. `'FighterStunned'`
   - `broadcastWith(): array` → snake_case scalars only, never models. Always include `user_id` for fighter events. Use `$user->displayHandle()` for handles and `$user->characterForBoss($bossId)` for character.
   - Full PHPDoc on every method and constructor (see `.ai/guidelines/code-style.md`).
3. **Bus key**: add a constant to `BusEvent` in `resources/js/battlefield/constants.js` (`FIGHTER_STUNNED: 'fighter-stunned'`), and add its expectation to the `BusEvent` test in `tests/js/constants.test.js`.
4. **Echo layer**: `resources/js/battlefield/index.js` → add `FighterStunned: BusEvent.FIGHTER_STUNNED` to `ECHO_EVENT_MAP`. The key must equal `broadcastAs()` exactly; Echo subscribes with a leading `.`, which `attachEchoListeners()` adds for you.
5. **Scene handler**: `resources/js/battlefield/scene.js` → add `[BusEvent.FIGHTER_STUNNED]: payload => this.<manager>.handleX(payload)` to `_busHandlers`. The existing loop registers it with `bus.on` and releases it with `bus.off` on `shutdown`; don't add a separate `bus.on`. Put decision logic in a pure module with a Vitest test (`tests/js/`).
6. **Dispatch site**: from `EventController`, use `$this->dispatchSafely(new X(...))` (a `rescue()` wrapper, because a broadcast must never fail ingestion). From a command or Livewire action, use `event(new X(...))` / `X::dispatch(...)`.
7. **IDE bridge** (only if the IDE plugins should react): add a `channel.listen('.<Name>', …)` in `resources/js/ide-bridge.js`.

## Renaming or changing a payload

Grep every consumer: `ECHO_EVENT_MAP`, `BusEvent`, the scene handler, **and** `resources/js/ide-bridge.js` / `ide-bridge-internal.js`, which listen to some events by raw name. Update them all in the same commit. If the payload feeds the boot state, also check `snapshotState()` and the `data-battlefield-state` round-trip (`battlefield` skill).

## Conventions checklist (must all hold)

- [ ] `broadcastAs()` string === `ECHO_EVENT_MAP` key
- [ ] `BusEvent` constant added (+ `tests/js/constants.test.js`); handler in `_busHandlers` (auto torn down on shutdown)
- [ ] Payload keys snake_case and scalar; every key the JS reads exists in `broadcastWith()`
- [ ] BroadcastShapeTest case + catch-all entry added, and seen failing first
- [ ] `dispatchSafely()` / `rescue()` around dispatch on the hook path
- [ ] Event added to the catalog table in `.ai/domain/broadcasting.md`
- [ ] Ran `spin exec php php artisan test --compact --filter=BroadcastShape`, `npx vitest run`, and `npm run build`

Then request review from the `broadcast-reviewer` agent.
