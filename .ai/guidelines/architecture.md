# Architecture (project-specific)

## Shape of the app

```
hooks on dev machines ──POST /api/events──▶ EventController
                                              │  (hook.token middleware = Bearer users.hook_token)
                                              ├─ Event row (append-only usage ledger)
                                              ├─ DamageService (boss HP, kills, respawn)
                                              └─ broadcast events ──Reverb 'battlefield' channel──▶ Phaser scene / Livewire
```

- `app/Http/Controllers/Api/` — ingestion + IDE endpoints. Controllers stay thin: parse/validate, delegate to `app/Services/`, dispatch broadcasts.
- `app/Services/` — all business logic (DamageService, DamageTotals, caches, Slack, Recap). New aggregation/probing logic goes here, one class per responsibility.
- `app/Events/` — broadcastables. The PHP↔JS contract rules live in `.ai/domain/broadcasting.md`; changes there require the `broadcast-reviewer` agent.
- `app/Livewire/` — page components (Battlefield, Profile, AdminUsage). Admin pages gate on `can:admin` (`users.is_admin`).
- Routes: `routes/api.php` (hook + IDE), `routes/web.php` (pages + served install scripts), `routes/channels.php` (broadcast auth), `routes/console.php` (schedules).
- Install scripts are Blade-rendered shell scripts (`resources/views/install-script.blade.php` & friends) served over HTTP — they are code, review them like code, and keep them idempotent (re-running is the upgrade path).

## Rules

- Event rows are append-only; aggregates always derive from `events`, never from mutable counters.
- Anything cached (`DamageTotals`, charging cache, position cache) documents its TTL and invalidation trigger next to the `Cache::` call.
- Scheduled work = artisan command + `routes/console.php` schedule entry + `withoutOverlapping()` when it touches external APIs.
- Don't add new base folders under `app/` without approval; follow the existing layout.
