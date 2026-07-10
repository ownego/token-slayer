# token-slayer — Agent Entry Point

**Read `CLAUDE.md` first** — it is the canonical agent guide (hand-written header + Laravel Boost guidelines, which inline `.ai/guidelines/`).

Then, before working in an area, read its domain doc:

- `.ai/domain/battlefield.md` — Phaser scene, sprites, snapshot/teardown
- `.ai/domain/token-tracking.md` — hook → EventController → damage pipeline
- `.ai/domain/broadcasting.md` — PHP↔JS broadcast contract
- `.ai/domain/accounts.md` — org accounts, attribution, quota probing

## Non-negotiables

1. TDD: failing test first, watch it fail, then implement.
2. `spin exec php php artisan …` — never bare `php`.
3. `npm run build` after any JS/CSS change; verification happens on staging, not locally.
4. Broadcast three-name alignment (`broadcastAs()` / `ECHO_EVENT_MAP` / scene bus) must hold in the same commit.
5. `env()` only inside `config/*.php`.
6. Never commit spec/plan/design docs (`docs/superpowers/**`) or `pint.json`; `.ai//.claude` agent-config is committed.
7. Commit messages: Angular convention with project scopes (see `.claude/skills/commit/SKILL.md`).
