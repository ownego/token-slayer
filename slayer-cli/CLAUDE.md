# slayer-cli

token-slayer's client-side CLI/TUI for managing local Claude account slots and switching between them, keeping the token-slayer hook's attribution correct.

## Stack

- Python 3.10+ / Click (CLI) / Textual (TUI) / pydantic v2 / keyring / httpx

## Commands

```
cd slayer-cli && pip install -e ".[dev]"           # install (editable, with dev deps)
cd slayer-cli && python -m pytest -q                # run tests
cd slayer-cli && python -m pytest -q -k "test_name"  # single test
cd slayer-cli && python -m build                     # build wheel
```

## Architecture

Package organized by responsibility, not by layer:

```
src/slayer_cli/
  __main__.py       # python -m slayer_cli entry
  version.py
  constants.py       # project-wide constants — no magic strings elsewhere
  errors.py          # SlayerError + subclasses
  models/            # pydantic models: account, provider, usage, history
  platform/           # OS paths, subprocess, HTTP, caching
  credstore/           # active credential storage (file / Keychain / claude.json)
  accounts/            # slot CRUD, switch, history, detection
  usage/               # quota fetch/parse/cache
  provider/            # writes account-provider/active.json for the hook
  auth/                # PKCE + org-uuid beacon
  cli/                 # Click entrypoint + subcommands (thin)
  tui/                 # Textual app + widgets (thin)
```

`models/`, `accounts/`, `usage/`, `provider/`, `auth/`, `credstore/` hold all logic. `cli/` and `tui/` are thin — they parse input, call a service, render output. Never put business logic in a Click command or a Textual widget.

## Watch out for

- **Tokens are NEVER logged, printed, put in errors, or shown in the TUI** — not even at DEBUG. Test fixtures use the literal `sk-ant-oat01-TESTTOKEN`.
- **`active.json` needs a non-blank `org_uuid`** — the token-slayer hook rejects the file otherwise.
- **No auto-swap in v1** — switching is always an explicit user action.
- **All credential files `0600`, all credential dirs `0700`.**
- Detailed conventions live in `.ai/guidelines/` (`code-style.md`, `testing.md`); domain knowledge lives in `.ai/domain/`, indexed by `.ai/guidelines/domain.md`.
