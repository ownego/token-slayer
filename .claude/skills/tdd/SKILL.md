---
name: tdd
description: Use for EVERY behavior change in this repo — enforced red/green workflow with the project's source→test path mapping. Write the failing test first, watch it fail, then implement.
---

# TDD Workflow (mandatory)

1. **Locate the test file** from the mapping below. If it doesn't exist, create it with `spin exec php php artisan make:test --pest <Name>` (add `--unit` for pure logic), then move it into the mapped folder to match its siblings.
2. **Write the failing test**: the smallest test that pins the new behavior. Use factories/datasets per `.ai/guidelines/testing.md`, and check `tests/Pest.php` helpers first (`fakeAnthropic()`, `fakeRedis()`, `livesOn()`).
3. **Run it and watch it fail.** Confirm it fails for the *right reason*: missing behavior, not a typo or fixture error.
4. **Implement the minimal change** that makes it pass.
5. **Run the scoped test again**: green.
6. **Run the surrounding suite** for the touched area (the same folder path) to catch regressions.
7. **Format** with `vendor/bin/pint --dirty --format agent`, then commit via the `commit` skill (only when asked).

Skipping step 3 is the most common violation: a test that never failed proves nothing. Bug fixes start with a test that reproduces the bug at the real call site. If there is no seam to test at, say so rather than writing a shallow test.

## Source → test mapping

| Source | Test |
|---|---|
| `app/Http/Controllers/Api/EventController.php` | `tests/Feature/Api/EventIngestionTest.php` (+ `EventMembershipRecordingTest.php` for membership) |
| `app/Http/Controllers/Api/ProvisionedAccountController.php` | `tests/Feature/Api/ProvisionedConfirmTest.php`, `tests/Feature/Provisioning/ProvisionedAccountEndpointTest.php` |
| `app/Http/Controllers/Api/{Ide,Admin,Ccrc}/X.php` | `tests/Feature/Api/{Ide,Admin,Ccrc}/`, one file per endpoint (e.g. `Ide/AuthExchangeTest.php`, `Admin/CodexAdminControllerTest.php`) |
| `app/Http/Controllers/Api/StateController.php` | `tests/Feature/Api/StateEndpointTest.php` |
| `app/Http/Controllers/X.php` (web) | `tests/Feature/XTest.php` (e.g. `AvatarProxyTest`, `HistoryPageTest`, `SlayerWheelTest`) |
| `app/Http/Middleware/X.php` | `tests/Feature/Middleware/XTest.php` (`HookTokenAuthTest`) |
| `app/Events/*` (broadcast shape) | `tests/Feature/Events/BroadcastShapeTest.php` |
| `app/Listeners/X.php` | `tests/Feature/Listeners/XTest.php` |
| `app/Notifications/X.php` | `tests/Feature/Notifications/XTest.php` |
| `app/Console/Commands/X.php` | `tests/Feature/Console/XTest.php` |
| `app/Livewire/X.php` | `tests/Feature/Livewire/XTest.php` |
| `app/Filament/**` (resources, pages, widgets, actions) | `tests/Feature/Filament/<Thing>Test.php` |
| `app/Policies/*`, `Services/Roles/*` | `tests/Feature/Roles/` |
| `app/Services/X.php` (touches DB/HTTP/cache) | `tests/Feature/Services/XTest.php` |
| `app/Services/{Analytics,Attribution,GitHub}/X.php` | `tests/Feature/Services/{Analytics,Attribution,GitHub}/XTest.php` |
| `app/Services/Accounts/X.php` | `tests/Feature/Accounts/XTest.php` (DB-backed) or `tests/Unit/Services/Accounts/XTest.php` (pure) |
| `app/Services/Provisioning/*`, `AccountProvisioningService`, `CodexProvisioningService` | `tests/Feature/Provisioning/` |
| `app/Services/Client/ReleaseArtifacts.php` | `tests/Feature/Client/ReleaseArtifactsTest.php` |
| Pure service / value object (no framework surface) | `tests/Unit/Services/<same subpath>/XTest.php` (e.g. `Unit/Services/Events/ModelUsageParserTest.php`) |
| `app/Models/X.php` | `tests/Feature/Models/XTest.php` (DB) or `tests/Unit/Models/` |
| `app/Enums/X.php`, `app/Support/X.php` | `tests/Unit/Enums/XTest.php`, `tests/Unit/Support/XTest.php` |
| `config/game.php` | `tests/Unit/GameConfigTest.php` |
| `resources/views/install-script.blade.php` | `tests/Feature/InstallScriptTest.php` (+ `HookSnippetTest.php` for the registered-event list) |
| `resources/views/install-script-ps1.blade.php` | `tests/Feature/InstallScriptPs1Test.php`. Hook changes need **both** install tests (lockstep rule) |
| `cowork-*.blade.php`, `userscript.blade.php` | `tests/Feature/CoworkTrackerTest.php`, `tests/Feature/UserscriptTest.php` |
| `resources/js/battlefield/<module>.js` (pure logic) | `tests/js/<module>.test.js`, or the matching subfolder (`tests/js/{boss,attacks,managers,character-preview}/`) |
| Page-level smoke (renders, no JS errors) | `tests/Browser/` (Pest 4 browser; only run when asked) |

Phaser-coupled JS: there is no real Phaser runtime in Vitest. Two options:
- **Preferred:** extract the decision logic into a pure module (`move-geometry.js`, `minion-fight.js` pattern) and test that.
- **Manager classes** (`tests/js/managers/*.test.js`): `vi.mock('phaser', () => ({ default: {} }))` plus a hand-built fake scene object. Copy an existing file's stub rather than inventing one. This covers manager logic, not rendering.

Scene wiring and visuals are verified on staging.

## Commands

- PHP: `spin exec php php artisan test --compact --filter=<Name>` or `… tests/Feature/<Path>` (container down → `docker start token-slayer-php-1`)
- JS: `npx vitest run tests/js/<file>.test.js`
- More on scoping and reading failures: the `test` skill.
