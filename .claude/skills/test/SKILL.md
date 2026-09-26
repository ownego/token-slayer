---
name: test
description: Use when running or scoping tests in this repo — correct commands, how to scope runs, environment gotchas, and how to read failures.
---

# Running tests

## PHP (Pest)

```bash
spin exec php php artisan test --compact --filter=<TestName or test description fragment>
spin exec php php artisan test --compact tests/Feature/Api/EventIngestionTest.php
spin exec php php artisan test --compact tests/Feature/Accounts          # a whole area
```

- Always use `--compact`. Always scope with `--filter` or a path. Run the full suite only before finishing a branch.
- Bare `php` is WRONG (it points at an unrelated container). Only `spin exec php php …`.
- `service "php" is not running` → `docker start token-slayer-php-1`, then retry.
- A single file should finish in seconds. If a run hangs or throws DB/Redis connection errors, check `docker ps` for other projects' stacks contending for ports before re-running, and report what you find.
- Test env (`phpunit.xml`): sqlite `:memory:`, `CACHE_STORE=array`. Feature tests opt into `RefreshDatabase` per file (it is **not** global in `tests/Pest.php`), so copy the `uses(...)` line from a sibling.
- No real Redis or network: use `fakeRedis($store)` for `SubagentCountCache`, and `fakeAnthropic()` (calls `Http::preventStrayRequests()`) / `Http::fake` for Anthropic, Codex, GitHub and Slack. Fixtures live in `tests/fixtures/{anthropic,codex}/`.

### Minimum gates by area

| Changed | Run at least |
|---|---|
| `app/Events/*`, broadcast payloads | `--filter=BroadcastShape` |
| `EventController`, ingestion | `tests/Feature/Api/EventIngestionTest.php` |
| Hook/install templates | `tests/Feature/InstallScriptTest.php tests/Feature/InstallScriptPs1Test.php tests/Feature/HookSnippetTest.php` |
| Accounts / rebalance | `tests/Feature/Accounts tests/Unit/Services/Accounts` |
| Provisioning | `tests/Feature/Provisioning tests/Feature/Api/ProvisionedConfirmTest.php` |
| Filament admin | `tests/Feature/Filament` |

## JavaScript (Vitest)

```bash
npx vitest run                              # all (~30 files)
npx vitest run tests/js/layout.test.js
npx vitest run tests/js/managers            # a folder
```

- `tests/js/pack-sprites.test.js` runs the real sprite packer. A sprite-sheet error there is a real failure, not noise.
- No watch mode in agent sessions; always use `run`.
- JS changes also need `npm run build` before they exist anywhere but your editor.

## Browser (Pest 4)

`tests/Browser/` (`BattlefieldSmokeTest`, `BattlefieldResponsiveTest`, `SetupSmokeTest`) is excluded from routine runs. Don't start it unless asked.

## Reading failures

- Report failures verbatim (assertion + location). Never summarize a red run as "mostly passing".
- A test that fails for the wrong reason (fixture, typo) is not a valid RED for TDD. Fix the test first.
- A timeout far past the baseline is a symptom to investigate, not something to wait out or blindly retry.
