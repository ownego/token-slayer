# Architecture (project-specific)

## Shape of the app

```
hooks on dev machines ──POST /api/events──▶ EventController
                                              │  (hook.token middleware = Bearer token, matched against sha256 in users.hook_token)
                                              ├─ Event row (append-only usage ledger; account_id + model resolved per event)
                                              ├─ DamageService (boss HP, kills, respawn via BossArena)
                                              ├─ SubagentCountCache (Redis presence → cosmetic minion swarm, no damage)
                                              └─ broadcast events ──Reverb public 'battlefield' channel──▶ Phaser scene / Livewire
```

- `app/Http/Controllers/Api/` — ingestion + IDE endpoints. Controllers stay thin: parse/validate, delegate to `app/Services/`, dispatch broadcasts.
- Same thin-entrypoint rule applies to **artisan commands, jobs, and Filament actions**: they parse/iterate/delegate/report, and hand non-trivial per-item logic to an `app/Services/` class. Pattern: `ProbeAccountUsage` → `UsageProber`, `SyncAccountProfiles` → `AccountProfileSyncer`, the Connect action → `AccountConnectService`.
- `app/Services/` — all business logic. New aggregation/probing logic goes here, one class per responsibility.
- `app/Events/` — broadcastables (`ShouldBroadcastNow` on the public `battlefield` channel) plus one plain domain event, `AccountTokenRejected` (→ `SendReauthAlert` listener). The PHP↔JS contract rules live in `.ai/domain/broadcasting.md`; changes there require the `broadcast-reviewer` agent.
- `app/Livewire/` — page components (Battlefield, CharacterSelect, Profile, Setup, AdminUsage).
- `app/Filament/` — admin panel at `/dashboard` (`AdminPanelProvider`; old `/admin/*` URLs redirect there). Roles/permissions via Filament Shield + spatie/permission; a user is an admin when they hold any role (`User::isAdministrator()`, `Gate 'admin'`). `/admin/usage` gates on `can:view_usage_analytics`.
- Routes: `routes/api.php` (hook, provisioning, IDE, CCRC, Codex admin), `routes/web.php` (pages + served install scripts/userscript/wheel), `routes/channels.php` (broadcast auth), `routes/console.php` (schedules).
- Install scripts are Blade-rendered shell scripts (`resources/views/install-script.blade.php`, `install-script-ps1.blade.php`, `cowork-install-script.blade.php`, `cowork-watcher.blade.php`, `userscript.blade.php`) served over HTTP — they are code, review them like code, and keep them idempotent (re-running is the upgrade path). `/install` and `/install.ps1` are served from `ReleaseArtifacts` so the bytes match the digest the ingest response publishes.
- `extensions/vscode/` and `extensions/jetbrains/` — IDE plugins embedding the battlefield; they talk to `routes/api.php` `/api/ide/*` (bearer = `IdeAccessToken`). Each has its own README/SMOKE.md.

## Where things live (`app/`)

| Path | Holds |
|---|---|
| `Services/` (root) | Core pipeline + cross-cutting: `DamageService` (apply damage, chain kills), `BossArena` (current/next boss), `DamageTotals` (cached rolling-window aggregates), `AccountResolver` (hook claim → org account), `FighterChargingCache` / `FighterPositionCache` / `SubagentCountCache` (battlefield live state), `HookTokenRotator`, `InstallCommandPresenter`, `QuotaProjection`, `ProviderServiceFactory` (Claude vs Codex implementation per account) |
| `Services/` (root, accounts) | Claude: `AnthropicOAuthClient`, `AccountTokenRefresher`, `UsageProber`, `SessionAnchorer`, `AccountProfileSyncer`, `AccountConnectService`, `AccountProvisioningService`. Codex: `CodexOAuthClient`, `CodexConnectService`, `CodexProvisioningService`, `CodexUsageProber` |
| `Services/Accounts/` | Membership recording/caching + the rebalance/capacity model (`AccountRebalanceRecommender`, `RebalancePlanner`, `FleetSnapshot`, capacity/demand estimators) — see `.ai/domain/accounts.md` |
| `Services/Analytics/` | One query class per `/dashboard` analytics widget (`*Query`), shared `UsageFilters` + `Concerns/ScopesEventsByFilters` |
| `Services/Attribution/` | Unrecognized/unattached/expiring-account queries + `EventAttributionBackfiller` |
| `Services/Battlefield/` | Model flair (`ModelFlairResolver`, `AiModelSyncer`) |
| `Services/Events/` | `ModelUsageParser` / `TurnUsage` — hook `models` map → `events.model` |
| `Services/Provisioning/`, `Connect/`, `Contracts/` | Device grant resolution/backfills; connect-flow value objects; provider contracts (`UsageProberContract`, `GrantRevokerContract`, `AccountDisconnecterContract`) |
| `Services/GitHub/`, `Client/` | slayer-cli release relay (`GitHubClient`, `ReleaseResolver`, `CachedLatestVersion`); `ReleaseArtifacts` (rendered install scripts + digests) |
| `Services/Recap/`, `Slack/`, `Roles/` | Scheduled Slack recap; Slack display-name fetch; Shield default-role permissions |
| `Support/` | `CacheKeys` (central cache-key registry + invalidation), `DamageResult`, `HookVersionStatus`, `ModelName` |
| `Enums/`, `Exceptions/`, `Listeners/`, `Notifications/`, `Policies/` | String-backed enums (`Provider`, `AccountStatus`, `MembershipStatus`, …); named domain exceptions; `AnnounceBossKill`, `SendReauthAlert`; Slack notifications; Filament policies |

## Rules

- Event rows are append-only; aggregates always derive from `events`, never from mutable counters.
- Anything cached documents its TTL and invalidation trigger next to the `Cache::` call; register the key in `App\Support\CacheKeys` rather than owning a key string in the service.
- Redis is a hard dependency: the queue runs on it and `SubagentCountCache` uses the `Redis` facade directly (tests fake it with `fakeRedis()`).
- Scheduled work = artisan command + `routes/console.php` schedule entry + `withoutOverlapping()` when it touches external APIs.
- Artisan commands declare `#[Signature(...)]` / `#[Description(...)]` attributes; the name is `<domain-noun>:<verb>` (`event-attribution:backfill`, `accounts:probe`, `fighters:sweep-idle`).
- Controllers: validation in `app/Http/Requests/` FormRequest classes, never inline `$request->validate()`; build response arrays from named locals, not inlined expressions.
- Don't add new base folders under `app/` without approval; follow the existing layout.
