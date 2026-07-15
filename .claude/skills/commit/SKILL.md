---
name: commit
description: Use when committing changes in this repo — enforces the Angular commit convention with project scopes, staging discipline, and the files that must never be committed.
---

# Committing in token-slayer

## Message format (Angular convention)

```
<type>(<scope>): <subject>

<body — the why, wrapped at 72>

Co-Authored-By: <current Claude model> <noreply@anthropic.com>
```

- **type**: `feat` | `fix` | `refactor` | `test` | `chore` | `docs` | `perf`
- **scope** (pick the closest): `battlefield`, `boss`, `leaderboard`, `hooks` (install scripts / client helper), `api` (ingestion/IDE endpoints), `accounts`, `admin`, `broadcast`, `deps`
- **subject**: imperative, lower-case, no trailing period, ≤ 72 chars
- Body explains *why*, not what the diff shows. Omit it only for truly trivial changes.
- Always commit via HEREDOC so formatting survives:

```bash
git commit -m "$(cat <<'EOF'
feat(accounts): attribute ingested events to the claimed org account
...body...

Co-Authored-By: Claude <noreply@anthropic.com>
EOF
)"
```

## Staging discipline

- Stage explicit paths. Never `git add .` / `git add -A`.
- NEVER commit: `docs/superpowers/**` (specs/plans/design docs), ad-hoc planning markdown, `.env*` (except `*.example`), `pint.json`.
- `.ai/**` and `.claude/**` agent-config ARE committed (except `settings.local.json`).

## Hard rules

- New commits only — never `--amend` unless the user explicitly asks.
- Never `--no-verify`.
- Only commit when the user asked for a commit.
- Force-push requires explicit per-instance user confirmation.
