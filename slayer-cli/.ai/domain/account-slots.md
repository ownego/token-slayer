# Domain: Account Slots

## Overview

A **slot** = one stored Claude account. Slots on disk are the registry of known accounts — the source of truth for what slayer-cli can switch between. Each slot is a JSON file at `~/.config/<ns>/accounts/<name>.json` (file `0600`, dir `0700`). `<ns>` is `token_slayer` in prod or `token_slayer_stg` in staging, read from env `SLAYER_NS`.

## Key Models

| Model | Role |
|-------|------|
| `Account` | One slot: `name`, `email`, `org_uuid`, `plan`, `token`, `added_at`, `last_used`. `token` is excluded from `repr()`. |

## Adding a Slot

Two ways to add a slot:

```
snapshot (default):
  read active credential store
    → resolve org_uuid via beacon (POST to Anthropic messages endpoint, read response header)
    → resolve email from ~/.claude.json
    → write accounts/<name>.json

add --login (PKCE):
  generate PKCE challenge → print authorize URL
    → user logs in and pastes the returned code
    → exchange code for token (slayer-cli never handles the user's password/login form)
    → resolve org_uuid via beacon → write accounts/<name>.json
```

**The tool never logs in on the user's behalf** — `add --login` only exchanges a code the user pasted after authenticating themselves.

## Key Files

| File | Purpose |
|------|---------|
| `models/account.py` | `Account` pydantic model |
| `accounts/store.py` | Read/write/list slot JSON files, `0600`/`0700` enforcement |
| `accounts/add.py` | Snapshot and `--login` add flows |
| `accounts/detect.py` | Resolve current active credential + email for snapshot |
| `auth/pkce.py` | PKCE challenge/authorize-URL generation + code-for-token exchange, backing `add --login` |

## Key Invariants

- **A slot's `token` field is never included in logs, errors, or `repr()`.**
- **The active-slot pointer lives in `state.json`, not in the slot file itself** — a slot has no "is active" field.
- **`add --login` never touches the user's Anthropic password** — it only exchanges a PKCE code the user pasted.
