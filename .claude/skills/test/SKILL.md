---
name: test
description: Use when running or scoping tests in this repo — correct commands, how to scope runs, environment gotchas, and how to read failures.
---

# Running tests

## PHP (Pest)

```bash
spin exec php php artisan test --compact --filter=<TestName or method>
spin exec php php artisan test --compact tests/Feature/Api/EventIngestionTest.php
```

- Always `--compact`. Always scope with `--filter` or a path — full suite only before finishing a branch.
- Bare `php` is WRONG (points at an unrelated container). Only `spin exec php php …`.
- `service "php" is not running` → `docker start token-slayer-php-1`, retry.
- Broadcast contract changes: run `--filter=BroadcastShape` as the minimum gate.

## JavaScript (Vitest)

```bash
npx vitest run                      # all (11+ files, includes pack-sprites build)
npx vitest run tests/js/layout.test.js
```

- `[pack-sprites]` output at the head of the run is a build step — an error there is a real failure.
- No watch mode in agent sessions; always `run`.

## Reading failures

- Report failures verbatim (assertion + location) — never summarize a red run as "mostly passing".
- A test that fails for the wrong reason (fixture/typo) is not a valid RED for TDD — fix the test first.
- Browser tests (`tests/Browser/`) are excluded from routine runs; don't start them unless asked.
