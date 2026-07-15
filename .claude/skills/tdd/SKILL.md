---
name: tdd
description: Use for EVERY behavior change in this repo — enforced red/green workflow with the project's source→test path mapping. Write the failing test first, watch it fail, then implement.
---

# TDD Workflow (mandatory)

1. **Locate the test file** from the mapping below (create it if missing, matching sibling structure).
2. **Write the failing test** — smallest test that pins the new behavior. Use factories/datasets per `.ai/guidelines/testing.md`.
3. **Run it and watch it fail** — confirm it fails for the *right reason* (missing behavior, not a typo/fixture error).
4. **Implement the minimal change** that makes it pass.
5. **Run the scoped test again** — green.
6. **Run the surrounding suite** for the touched area (same directory filter) to catch regressions.
7. **Format**: `vendor/bin/pint --dirty --format agent`, then commit via the `commit` skill (when asked).

Skipping step 3 is the most common violation — a test that never failed proves nothing.

## Source → test mapping

| Source | Test |
|---|---|
| `app/Livewire/X.php` | `tests/Feature/Livewire/XTest.php` |
| `app/Services/X.php` | `tests/Feature/Services/XTest.php` |
| `app/Http/Controllers/Api/EventController.php` | `tests/Feature/Api/EventIngestionTest.php` |
| `app/Http/Controllers/Api/Ide/X.php` | `tests/Feature/Api/Ide/XTest.php` |
| `app/Events/*` (broadcast shape) | `tests/Feature/Events/BroadcastShapeTest.php` |
| `app/Console/Commands/X.php` | `tests/Feature/Console/XTest.php` |
| `app/Models/X.php` | `tests/Feature/Models/XTest.php` |
| `resources/views/install-script*.blade.php` | `tests/Feature/InstallScriptTest.php` / `HookSnippetTest.php` |
| `resources/js/battlefield/<module>.js` (pure logic) | `tests/js/<module>.test.js` |

Phaser scene-coupled JS has no test harness — extract the decision logic into a pure module first (that's the testable unit), then verify the wiring on staging.

## Commands

- PHP: `spin exec php php artisan test --compact --filter=<Name>` (container down → `docker start token-slayer-php-1`)
- JS: `npx vitest run tests/js/<file>.test.js`
