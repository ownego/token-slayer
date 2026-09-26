# token-slayer

Internal gamified token-usage tracker: developers' Claude Code / Codex / Antigravity / Cowork hooks and the claude.ai userscript post usage to `POST /api/events`, which deals damage to a shared boss on a real-time Phaser battlefield. Admins also manage the org's shared Claude/Codex accounts (attribution, quota probing, rebalancing) in a Filament panel. Laravel 13 · Livewire 4 · Filament 5 · Reverb · Redis · Phaser 3 · Tailwind 4 · Pest 4 · Vitest.

## Canonical commands

- PHP tests: `spin exec php php artisan test --compact [--filter=…]` (bare `php` targets the WRONG container)
- Container down? `docker start token-slayer-php-1`
- JS tests: `npx vitest run [tests/js/<path>.test.js]`
- Build (required after ANY JS/CSS change): `npm run build` (packs the fighter atlas, then `vite build`)
- Format: `vendor/bin/pint --dirty --format agent`
- Artisan: `spin exec php php artisan …` (`route:list --path=api`, `schedule:list`, …)

## Local stack (`docker-compose.yml` + `docker-compose.dev.yml`)

| Service | Role |
|---|---|
| `php` | App on host `${APP_PORT:-8000}` |
| `reverb` | `reverb:start` websockets on `${REVERB_PORT:-8080}` |
| `worker` | `queue:work redis` |
| `pgsql` | Postgres 18 (`.env.example` itself defaults to `DB_CONNECTION=sqlite`) |
| `redis` | Valkey 8 — required: queue backend + `SubagentCountCache` presence keys. Set `REDIS_HOST=redis` inside Docker |

No scheduler container in dev (staging adds `scheduler` = `schedule:work`). The staging compose file (`docker-compose.staging.yml`) runs behind Cloudflare Tunnel — see `README.md`.

## Architecture invariants

- `POST /api/events` is the single write path for usage; `events` rows are append-only — aggregates always derive from them.
- Broadcast three-name alignment: `broadcastAs()` === `ECHO_EVENT_MAP` key (`resources/js/battlefield/index.js`) → scene bus key. See `.ai/domain/broadcasting.md`.
- `snapshotState()` must round-trip with the `data-battlefield-state` boot payload (scene reboots on rotate).
- Fighter sprite sheets stay `frameWidth: 100` — never upscale.
- Account attribution is per-event (`events.account_id`), never inferred from user membership.
- Subagent presence / minion swarm is cosmetic only — never feeds damage or HP.
- Changing the hook template in a way clients must pick up → bump `token_slayer.hook_version` (`config/token_slayer.php`).

## Domain docs — read the relevant one before working in that area

| `.ai/domain/` file | Covers |
|---|---|
| `battlefield.md` | Phaser scene, sprites, snapshot/teardown invariants, companions (Necromancer, BatSwarm, per-fighter subagent minion swarm) |
| `token-tracking.md` | hook → EventController → damage pipeline, providers, per-event model, subagent dispatch tracking, install scripts |
| `broadcasting.md` | PHP↔JS broadcast contract rules |
| `accounts.md` | org accounts (Claude + Codex), attribution chain, provisioning, quota probing, rebalance/reconciliation |

Convention docs also live scattered next to the code they describe — these do NOT auto-load unless you're already working in that path, so check for one before touching a directory that has a `CLAUDE.md`/`CREDITS.md`/`README.md`:

| File | Covers |
|---|---|
| `resources/js/battlefield/CLAUDE.md` | JS manager conventions, fighter entry shape, Key Files table (keep in sync when adding/removing a `resources/js/battlefield/**` module) |
| `public/assets/battlefield/CLAUDE.md` + `CREDITS.md` | Sprite sheet formats, fighter/boss/companion/minion roster, source/license |
| `.claude/skills/battlefield/SKILL.md` | Deep battlefield operational guide |
| `extensions/vscode/README.md`, `extensions/jetbrains/README.md` (+ `SMOKE.md`) | IDE plugins that embed the battlefield via `/api/ide/*` |
| `tests/fixtures/anthropic/README.md` | How the captured Anthropic fixtures were obtained |

## Project skills & agents (`.claude/`)

| Name | Use when |
|---|---|
| `tdd` skill | Any behavior change — red/green + source→test path mapping |
| `test` skill | Running/scoping tests, reading failures |
| `commit` skill | Every commit — Angular convention + project scopes |
| `battlefield` skill | Working in `resources/js/battlefield/` |
| `scaffold-broadcast-event` skill | Adding/renaming a broadcast event |
| `broadcast-reviewer` agent | Review of `app/Events/*`, `routes/channels.php`, Echo listeners |
| `battlefield-reviewer` agent | Review of `resources/js/battlefield/**` |

## Common tasks

| Task | Do this |
|---|---|
| New broadcast event | `scaffold-broadcast-event` skill; shape test in `tests/Feature/Events/BroadcastShapeTest.php`; `broadcast-reviewer` |
| New battlefield module/manager | Pure logic in its own module + Vitest test; wire in `scene.js`; add a Key Files row in `resources/js/battlefield/CLAUDE.md` |
| New sprite (fighter/boss/companion/minion) | Follow `public/assets/battlefield/CLAUDE.md`; credit in `CREDITS.md`; `npm run build` |
| New scheduled job | `#[Signature('<noun>:<verb>')]` command (thin) → Service; entry in `routes/console.php` (+ `withoutOverlapping()` for external APIs) |
| New config value | Key in `config/token_slayer.php` / `game.php` / `github.php` with a cast; read via `config()`; document in `.env.example` if env-driven |
| New admin page/widget | `app/Filament/{Pages,Widgets,Resources}`; data from a `Services/Analytics/*Query` class; policy in `app/Policies/` |
| New cache | Key in `App\Support\CacheKeys`; TTL + invalidation trigger documented at the `Cache::` call |

## Workflow

- New fix/feature work goes in its own worktree (`.worktrees/<name>`) branched from `origin/master`.
- TDD is mandatory — use the `tdd` skill (failing test first, watch it fail).
- Commit via the `commit` skill; never commit `docs/superpowers/**`, spec/plan docs, or `pint.json`. `.ai/` and `.claude/` ARE committed.
- `env()` only inside `config/*.php`.
- The team verifies on staging, not locally — build + deploy before claiming a frontend change works. For a bug, reproduce it on unfixed staging first, then deploy the fix and rerun the same repro.
- No CI and no hooks enforce any of this — the rules above are only as good as the person/agent following them.

## Watch out for

- Detailed rules live in `.ai/guidelines/` (inlined below by Laravel Boost — edit them there, never inside the boost block; `composer update` re-runs `boost:update`).
- `AGENTS.md` (hand-written header + Boost block) and `GEMINI.md` (pure Boost output) serve other agents — `AGENTS.md`'s header points here; keep it that way rather than duplicating this file.
- **Before finishing a branch, check whether the docs above cover what you just built.** These have drifted badly before (a 134-commit feature merged with zero `accounts.md` updates; ~130 battlefield commits with zero updates across 3 docs) because nothing enforces it — no CI, no hook. If you added a new manager/service/domain concept, add a line for it now, in the same PR.

<laravel-boost-guidelines>
=== .ai/architecture rules ===

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

=== .ai/code-style rules ===

# Code Style (project-specific)

These rules extend the Boost/Laravel defaults above. When they conflict, these win.

## PHP

- Full PHPDoc blocks on every method, property, AND constant — always the 3+ line block form, never the compressed `/** one line */` form:
  - Properties and constants: multi-line block with a description line, then `@var type` — e.g.:
    ```php
    /**
     * Cache key of the lowercase-email → account-id map.
     *
     * @var string
     */
    public const string CACHE_KEY = 'accounts:email-map';
    ```
    Not `/** Cache key of the map. */` and not a bare undocumented constant.
  - Methods: description line(s), then `@param type $name` per parameter, then `@return type` (`@return void` included even when the type hint already says `void`).
  - Use `@inheritDoc` when implementing/overriding an interface method.
  - Reference: `~/Code/Ownego/bkv/bk-volume-api` (e.g. `app/Objects/Values/SessionToken.php`) is the canonical example of this constant-docblock style — match it.
- Do NOT rely on Pint to preserve PHPDoc: the stock `laravel` preset strips `@param`/`@return` tags it considers superfluous. A local `pint.json` with `"no_superfluous_phpdoc_tags": false` is the guard (kept per-machine, not committed).
- Constructor property promotion is used (Laravel 13 style) — unlike some sibling projects, it is allowed here.
- Exceptions: throw named domain exceptions (`App\Exceptions\...`), never a bare `\Exception`. Name them after the failure, not the layer (`UsageProbeException`, not `ServiceException`).
- `env()` is only ever called inside `config/*.php`. App code reads `config(…)`. Project config lives in `config/token_slayer.php` (hook version, update kill switch, Anthropic OAuth, probing, rebalance tunables), `config/game.php` (boss HP, idle windows) and `config/github.php` (slayer-cli release relay); third-party credentials in `config/services.php`. Cast numerics in the config file, not at call sites, and add every new key to `.env.example` if it needs a per-environment value.
- Enum keys in TitleCase; string-backed enums for anything that persists or broadcasts.
- Descriptive names over short ones: `isRegisteredForDiscounts()`, not `discount()`.

## Services & external integrations

- Prefer small, single-responsibility classes with a short action method over one broad service holding many methods. Put the descriptiveness in the CLASS name so the method can be a short verb — `execute()`, `handle()`, `__invoke()`, or `get()` for a query object. Reference sibling `mysbox-api`: e.g. `OrderResellerService::execute()`, not a grab-bag service with a long `processOrderReseller…()` method. When a service accretes several distinct responsibilities (or a method name grows qualified like `buildXForY`), split each into its own class named after the responsibility. This does NOT contradict "descriptive names over short ones" above: descriptive naming still governs predicate/query methods on entity-like classes; for action/query classes the descriptiveness lives in the class name and the single action method stays short. A single class exposing several tightly-related read queries (the existing `DamageTotals` shape) is acceptable, but a new aggregation surface should default to per-query classes.
- External integrations (Slack, email, third-party APIs) go through a shared, reusable abstraction — never duplicate transport (HTTP call, auth, retry) per feature. When a second consumer of an integration appears, extract the transport into a service under `app/Services/<Integration>/` (and, when it helps, an interface under `app/Services/Contracts/` — the existing home of `UsageProberContract` & co.); features depend on the abstraction and only build their payload. Reference sibling `mysbox-api` for the house shape.
- Slack outbound uses Laravel's Slack notification channel (`laravel/slack-notification-channel`): one `Notification` class per message type in `app/Notifications/`, payload built with the Block Kit `SlackMessage` builder in `toSlack()`, sent via `Notification::route('slack', …)->notify(new XNotification(…))`. Adding a message type = a new Notification class, never new transport code. Reference: `SendReauthAlert` → `AccountTokenRejectedNotification`. Legacy exceptions — `AnnounceBossKill` and `RecapPoster` still `Http::post` to the notifier webhook; don't copy them.
- No `Log::` on normal/production paths in service or integration code. Signal failure with a named domain exception (`App\Exceptions\…`), the `rescue()` helper, or a typed return value — not log-and-continue. Logging is a dev-only aid; gate any diagnostic behind `App::environment('local')`.

## Comments

- PHP: prefer PHPDoc over inline comments; inline comments only for genuinely non-obvious logic (race workarounds, protocol quirks) — state the constraint, not what the next line does.
- JavaScript: manager/class public methods get Google-style JSDoc (`@param`/`@returns`); pure/utility functions get a single-line comment at most. Do not apply the PHP DocBlock convention to JS.

## Git

- Commit messages follow the `commit` skill (Angular convention with project scopes).
- Never commit spec files, implementation plans, or design docs (`docs/superpowers/**`, ad-hoc `*.md` planning files). Agent-config under `.ai/` and `.claude/` IS committed.

=== .ai/frontend rules ===

# Frontend (project-specific)

## Livewire 4 + Alpine

- State lives server-side in the Livewire component; Alpine handles purely client-side interactivity (overlays, toggles, canvas HUD positioning). Don't duplicate server state into Alpine stores.
- Blade views receive already-shaped data from services — no query building or aggregation in blades or Livewire `render()` beyond delegating to a service.
- Check `resources/views/livewire/` and `resources/views/partials/` for an existing component before writing a new one.
- Admin UI is Filament v5 (`app/Filament/`, custom views in `resources/views/filament/`) — build admin pages/widgets there, not as new Livewire pages. Widget data comes from a `Services/Analytics/*Query` class.

## Battlefield (Phaser 3)

- All game code lives under `resources/js/battlefield/`. Deep knowledge: `.ai/domain/battlefield.md` and the `battlefield` skill.
- Decision logic must be extractable: pure functions in their own modules so Vitest can cover them without a Phaser runtime.
- Fighter sprite sheets are `frameWidth: 100` — never upscale or regenerate sheets at other sizes.
- Import game modules via the `@battlefield/...` alias (defined in both `vite.config.js` and `vitest.config.js`), not relative `../../` paths.
- Adding/removing a module under `resources/js/battlefield/**` → update the Key Files table in `resources/js/battlefield/CLAUDE.md` in the same commit. Changes there should go through the `battlefield-reviewer` agent.
- Adding a fighter/boss/companion/minion sprite → follow `public/assets/battlefield/CLAUDE.md` (formats, naming) and credit it in `CREDITS.md`. Fighter source frames live in `resources/assets/battlefield/fighters/` and are packed into a gitignored atlas by `scripts/pack-sprites.js` (runs automatically in `npm run build` / `npm run dev`).
- Tunables live in `resources/js/battlefield/config/` (`fighters.js`, `bosses.js`, `companions.js`, `layouts.js`, `timings.js`), not as literals in managers.
- New real-time event → use the `scaffold-broadcast-event` skill (PHP event → `ECHO_EVENT_MAP` in `resources/js/battlefield/index.js` → scene bus key).

## Build & verification

- Every JS/CSS change needs `npm run build` before it exists anywhere but your editor.
- Vite entry points: `resources/css/app.css`, `resources/js/app.js` (Echo + lazily-imported battlefield), `resources/js/ide-bridge.js` (IDE webview bridge). `VITE_REVERB_*` env values are baked in at build time — build with the target environment's values.
- The team does not test locally — changes are verified on staging. Build, then deploy per the standing staging workflow (rsync `public/build/`), then verify in the browser there.
- Tailwind 4 (CSS-first config); prefer existing utility patterns in the blades over new custom CSS.

=== .ai/testing rules ===

# Testing (project-specific)

## TDD is mandatory

Every behavior change starts with a failing test (see the `tdd` skill for the enforced workflow and the source→test path mapping). Write the test, run it, watch it fail for the right reason, then implement.

## PHP (Pest)

- Feature tests by default; unit tests only for pure logic with no framework surface.
- Test names read as behavior: `it('attributes the event to the matching org account', …)` — given/when/then discipline, not `it('works')`.
- Use factories (with custom states) for all models; check for an existing state before hand-rolling attributes.
- Data-driven cases use Pest datasets with named keys, not copy-pasted test bodies.
- Scope runs tightly: `spin exec php php artisan test --compact --filter=Name` or a filename. Full suite only before finishing a branch.
- External HTTP (Anthropic OAuth/usage API, Codex, GitHub, Slack) is always faked via `Http::fake`. Canonical response fixtures live in `tests/fixtures/anthropic/*.json` and `tests/fixtures/codex/*.json` — captured from real responses, never hand-invented. Use the `fakeAnthropic()` helper in `tests/Pest.php` (it also calls `Http::preventStrayRequests()`; pass per-endpoint overrides to simulate failures).
- Redis: no real server in the test environment. Anything touching `SubagentCountCache` uses the `fakeRedis(array &$store)` helper in `tests/Pest.php`; mutate `$store` to simulate key expiry.
- `tests/Pest.php` also has `livesOn()` for seeding a user's daily usage on an account (rebalance/capacity tests) — check it for existing helpers before writing setup code.
- phpunit.xml runs tests on sqlite with `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, `BROADCAST_CONNECTION=null`.
- Suites: `tests/Unit`, `tests/Feature` (mirrors `app/` — see the `tdd` skill's path mapping), `tests/Browser` (Pest 4 browser smoke tests).
- New broadcast event → add its case to `tests/Feature/Events/BroadcastShapeTest.php` first.
- Never delete tests without approval.

## JavaScript (Vitest)

- Tests live in `tests/js/**/*.test.js` (grouped by area in subfolders, e.g. `tests/js/boss/`, `tests/js/managers/`); run with `npx vitest run` (or a single file).
- Phaser code is not directly testable — extract decision logic into pure functions in their own modules (`move-geometry.js`, `minion-layout.js`, `minion-fight.js` pattern) and test those. If logic is buried in a scene callback, extraction comes first.
- `tests/js/pack-sprites.test.js` runs the real `scripts/pack-sprites.js` (skipped only when the fighter source sprites are absent); a sprite-sheet error there is a real failure, not noise.

## Environment gotchas

- `spin exec php` is the only correct PHP entrypoint (bare `php` targets an unrelated container).
- If `spin exec php` reports `service "php" is not running`: `docker start token-slayer-php-1`, then retry.

=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- laravel-echo (ECHO) - v2
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `spin exec php composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `spin exec php php artisan route:list`). Use `spin exec php php artisan list` to discover available commands and `spin exec php php artisan [command] --help` to check parameters.
- Inspect routes with `spin exec php php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `spin exec php php artisan config:show app.name`, `spin exec php php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `spin exec php php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `spin exec php php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Follow existing application Enum naming conventions.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `spin exec php php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `spin exec php php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `spin exec php php artisan list` and check their parameters with `spin exec php php artisan [command] --help`.
- If you're creating a generic PHP class, use `spin exec php php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `spin exec php php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `spin exec php php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `spin exec php composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `spin exec php php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `spin exec php php artisan make:test --pest SomeFeatureTest` instead of `spin exec php php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `spin exec php php artisan test --compact` or filter: `spin exec php php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

</laravel-boost-guidelines>
