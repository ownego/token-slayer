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
