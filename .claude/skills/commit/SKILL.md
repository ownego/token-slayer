---
name: commit
description: Use when committing changes in this repo — enforces the Angular commit convention with project scopes, staging discipline, and the files that must never be committed.
---

# Committing in token-slayer

## Message format (Angular convention)

```
<type>(<scope>): <subject>

<body: the why, wrapped at 72>

Co-Authored-By: <current Claude model> <noreply@anthropic.com>
```

- **type**: `feat` | `fix` | `refactor` | `test` | `docs` | `chore` | `perf` | `style` | `revert`. (`tweak` appears in history for small UI adjustments; prefer `fix` or `feat`.)
- **scope**: the closest area, taken from the scopes actually used in `git log`:

| Scope | Covers |
|---|---|
| `battlefield` | Phaser game (`resources/js/battlefield/**`), sprites, battlefield Livewire |
| `character-select`, `character-preview` | Character picker page / preview canvas |
| `api` | `EventController` ingestion, IDE/state endpoints (`routes/api.php`) |
| `hooks` | Install scripts, hook template, cowork watcher, userscript (history also has `hook` and `install`; use `hooks`) |
| `events` | `events` ledger / model tracking (`ModelUsageParser`, `events.model`) |
| `accounts` | Org accounts, OAuth, probing, anchoring, rebalance |
| `codex` | Codex-specific provider work |
| `provisioning` | Device grants, `/api/provisioned/*`, `AccountProvisioningService` |
| `attribution` | `AccountResolver`, unrecognized/backfill |
| `admin`, `filament`, `analytics` | Filament panel, resources, analytics widgets/queries |
| `setup`, `profile`, `guide` | The respective pages (`/setup`, `/profile`, `/guide`) |
| `config` | `config/*.php` changes on their own (e.g. a hook_version bump) |
| `broadcast` | Cross-cutting PHP↔JS broadcast contract |
| `jetbrains` (and `vscode` by analogy) | `extensions/*` IDE plugins |

Retired: `slayer`, used for the CLI before it moved to its own repo.

- **subject**: imperative, lower-case, no trailing period, ≤ 72 chars.
- **body**: explains *why*, not what the diff shows. Omit it only for truly trivial changes.
- Split commits by concern: a test-only commit (`test(api): cover …`) followed by `feat(api): …` is common here, and so is a separate `chore(config): bump hook_version …`.
- Always commit via a HEREDOC so the formatting survives:

```bash
git commit -m "$(cat <<'EOF'
feat(accounts): attribute ingested events to the claimed org account

...body...

Co-Authored-By: <current Claude model> <noreply@anthropic.com>
EOF
)"
```

## Staging discipline

- Stage explicit paths. Never `git add .` / `git add -A`.
- NEVER commit: `docs/superpowers/**` (specs/plans/design docs), ad-hoc planning markdown, `.env*` (except `*.example`), `pint.json`, or local helper scripts left untracked in the root (e.g. `deploy-staging.sh`, `stg-test-*.sh`) unless asked. Note that `docs/` is globally gitignored on the maintainer's machine.
- `.ai/**` and `.claude/**` agent-config ARE committed (except `settings.local.json`).
- Built assets (`public/build/`) are not committed; they are deployed by rsync.

## Hard rules

- New commits only. Never `--amend` unless the user explicitly asks.
- Never `--no-verify`.
- Only commit when the user asked for a commit.
- Work happens on a branch or worktree (`.worktrees/<name>` from `origin/master`), never directly on `master`.
- Force-push requires explicit per-instance user confirmation.
