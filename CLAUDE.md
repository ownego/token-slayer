# token-slayer

Internal gamified token-usage tracker: developers' Claude Code / Codex / claude.ai hook events deal damage to a shared boss on a real-time Phaser battlefield. Laravel 13 · Livewire 4 · Reverb · Phaser 3 · Tailwind 4 · Pest · Vitest.

## Canonical commands

- PHP tests: `spin exec php php artisan test --compact [--filter=…]` (bare `php` targets the WRONG container)
- Container down? `docker start token-slayer-php-1`
- JS tests: `npx vitest run [tests/js/<file>.test.js]`
- Build (required after ANY JS/CSS change): `npm run build`
- Format: `vendor/bin/pint --dirty --format agent`

## Architecture invariants

- `POST /api/events` is the single write path for usage; `events` rows are append-only — aggregates always derive from them.
- Broadcast three-name alignment: `broadcastAs()` === `ECHO_EVENT_MAP` key === scene bus key. See `.ai/domain/broadcasting.md`.
- `snapshotState()` must round-trip with the `data-battlefield-state` boot payload (scene reboots on rotate).
- Fighter sprite sheets stay `frameWidth: 100` — never upscale.
- Account attribution is per-event (`events.account_id`), never inferred from user membership.

## Domain docs — read the relevant one before working in that area

| `.ai/domain/` file | Covers |
|---|---|
| `battlefield.md` | Phaser scene, sprites, snapshot/teardown invariants |
| `token-tracking.md` | hook → EventController → damage pipeline, providers, install scripts |
| `broadcasting.md` | PHP↔JS broadcast contract rules |
| `accounts.md` | org accounts, attribution chain, quota probing |

## Watch out for

- TDD is mandatory — use the `tdd` skill (failing test first, watch it fail).
- Commit via the `commit` skill; never commit `docs/superpowers/**`, spec/plan docs, or `pint.json`.
- `env()` only inside `config/*.php`.
- The team verifies on staging, not locally — build + deploy before claiming a frontend change works.
- Detailed rules live in `.ai/guidelines/` (inlined below by Laravel Boost — edit them there, never inside the boost block).

<laravel-boost-guidelines>
=== .ai/architecture rules ===

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
- `env()` is only ever called inside `config/*.php`. App code reads `config('token_slayer.…')`. Cast numerics in the config file, not at call sites.
- Enum keys in TitleCase; string-backed enums for anything that persists or broadcasts.
- Descriptive names over short ones: `isRegisteredForDiscounts()`, not `discount()`.

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

## Battlefield (Phaser 3)

- All game code lives under `resources/js/battlefield/`. Deep knowledge: `.ai/domain/battlefield.md` and the `battlefield` skill.
- Decision logic must be extractable: pure functions in their own modules so Vitest can cover them without a Phaser runtime.
- Fighter sprite sheets are `frameWidth: 100` — never upscale or regenerate sheets at other sizes.

## Build & verification

- Every JS/CSS change needs `npm run build` before it exists anywhere but your editor.
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
- External HTTP (Anthropic OAuth/usage API, Slack) is always faked via `Http::fake`. When the Anthropic integration lands, canonical response fixtures live in `tests/fixtures/anthropic/*.json` — captured from real responses, never hand-invented — with a `fakeAnthropic()` helper in `tests/Pest.php`.
- Never delete tests without approval.

## JavaScript (Vitest)

- Tests live in `tests/js/*.test.js`; run with `npx vitest run` (or a single file).
- Phaser code is not directly testable — extract decision logic into pure functions in their own modules (`fighter-movement.js` pattern) and test those. If logic is buried in a scene callback, extraction comes first.
- The Vitest run includes the `pack-sprites` build step; a sprite-sheet error there is a real failure, not noise.

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
