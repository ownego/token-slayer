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
