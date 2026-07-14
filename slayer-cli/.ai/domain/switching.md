# Domain: Switching

## Overview

Switching makes a stored slot the active Claude account. It is always an explicit user action — v1 never auto-swaps. A switch touches the credential store, the local Claude config, slayer-cli's own state, the token-slayer attribution file, and a history log, in that order.

## `switch_to(name)` Pipeline

```
switch_to(name)
  → resolve org_uuid: slot's org, else re-beacon; persist back to the slot if newly found
      → write token to the active credential store
          → patch ~/.claude.json .oauthAccount (email/uuid/org)
              → set state.json active slot + touch slot's last_used
                  → reconcile provider active.json:
                        org known → write (org_uuid, email, source="switcher")
                        org unresolvable → REMOVE active.json (never leave it stale)
                      → append SwapHistoryEntry to swap-history.jsonl
```

Each step only runs after the previous one succeeds — a failed credential write aborts before `state.json` or `active.json` are touched, so the recorded active slot never points at a credential that wasn't actually written.

A slot can be baked without an `org_uuid` (a beacon failure at `add` time). On switch, `switch_to` re-beacons the missing org from the slot's token and, if resolved, persists it back to the slot file before continuing. The attribution file is then always reconciled: rewritten with the current org when known, or **removed** when the org still can't be resolved (network down and never beaconed). It is never left carrying the previous account's org — a stale-but-valid `active.json` would be silently accepted by the hook and misattribute the new account's usage to the old one. When the org is unresolvable the credential switch still succeeds (that is the primary function); only the attribution file is cleared, and a non-fatal warning is emitted to stderr.

## Key Models

| Model | Role |
|-------|------|
| `SwapHistoryEntry` | One line per switch: which slot, when, previous active slot. Appended to `swap-history.jsonl`. |

## Key Files

| File | Purpose |
|------|---------|
| `accounts/switch.py` | `switch_to()` pipeline |
| `accounts/history.py` | Append/read `swap-history.jsonl` |

## Key Invariants

- **v1 writes the GLOBAL `~/.claude/.credentials.json`** — concurrent Claude Code sessions on the same machine share one active account. This is an accepted v1 limitation; per-session isolation is future scope, not a bug to fix here.
- **No auto-swap in v1** — nothing switches accounts on the user's behalf (not on rate-limit, not on schedule).
- **`active.json` is reconciled on every switch** — rewritten with the current slot's org when known, or removed when the org can't be resolved. It is never left stale (pointing at the previously active account); the attribution contract (see `attribution.md`) depends on it being either correct or absent, never stale-but-valid.
- **A missing `org_uuid` is re-beaconed on switch and persisted back to the slot** — an org-less slot (from a beacon failure at `add` time) does not permanently lose attribution; the next switch resolves and stores its org.
- **Only the first account of a multi-account provisioned pull has a refresh token persisted in the slot store.** One v1 limitation rooted in `pull_and_setup` only storing a full grant for the first account:
  1. **Switch away and back preserves the refresh token.** `setup` writes the FULL grant (real refresh token, via `credstore.write_active_full`) for account 0, so Claude Code self-refreshes it directly. When switching to another slot, `switch_to` captures the outgoing account's live (possibly-rotated) grant and writes it back to that slot, preserving the refresh token. Switching back re-installs the full grant — the account still self-refreshes without needing to re-run `setup`.
  2. **Multi-account `setup` only makes the FIRST account self-refreshing by default.** `pull_and_setup` calls `write_active_full` (real refresh) for `index == 0` only; accounts 2..N get a slot with just the access token. Their refresh token — served once from the now-consumed cache — is not persisted in the slot, so they work only until their ~8 h access token expires, then need a re-`setup` (which re-provisions from the server). In practice the admin provisions one account per user, so the single-account flow (fully self-refreshing) is the norm; multi-account-in-one-pull is the degraded case.

  The second limitation is accepted v1 scope; the proper fix (persist the full grant per slot for all provisioned accounts) requires extending the slot schema and is deferred.
