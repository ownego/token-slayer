# Domain: Credential Store

## Overview

The "active credential" is whatever Claude Code itself reads to authenticate — slayer-cli doesn't invent its own credential format, it writes into Claude Code's existing storage so that switching a slot makes Claude Code itself use that account on its next call.

## Storage by Platform

```
switch writes active credential
  Linux/Windows → ~/.claude/.credentials.json (0600)
      merge .claudeAiOauth: {accessToken, refreshToken=null, expiresAt (long), scopes}
      preserve all other existing keys (e.g. mcpOAuth)
  macOS → system Keychain
```

Both platforms also patch `~/.claude.json` → `.oauthAccount` (`emailAddress`, `accountUuid`, `organizationUuid`) so Claude Code's own account display matches the switched-to slot.

`CLAUDE_CONFIG_DIR` is honored wherever `~/.claude` or `~/.claude.json` paths are resolved — slayer-cli never hardcodes the home-relative path.

## Key Files

| File | Purpose |
|------|---------|
| `credstore/file_store.py` | Linux/Windows JSON credential read/merge/write, pristine-credential backup + restore |
| `credstore/keychain_store.py` | macOS Keychain read/write |
| `credstore/claude_json.py` | Patch `~/.claude.json` `.oauthAccount` |
| `platform/paths.py` | Resolves `~/.claude`, `~/.claude.json`, `~/.claude/.credentials.json.slayer-bak`, honoring `CLAUDE_CONFIG_DIR` |

## Key Invariants

- **Writing a credential always merges, never overwrites the whole file** — unrelated keys like `mcpOAuth` survive a switch untouched.
- **All credential files are `0600`, all credential directories are `0700`**, set on every write, not just on first creation.
- **`CLAUDE_CONFIG_DIR` overrides the default `~/.claude` location everywhere** — no module resolves the path independently of `platform/paths.py`.

## Pristine-Credential Backup (Linux/Windows file path only)

The first time `file_store.write` overwrites an existing `.credentials.json`, it copies the file's current bytes to a sibling `.slayer-bak` file (`Paths.claude_credentials_backup`, `0600`) before writing the new token. This is **no-clobber**: only the first overwrite ever creates the backup, so it always holds the user's pristine pre-slayer login, never an intermediate switched-to account. `write` itself never reads or restores it.

`file_store.restore_backup(creds_file)` atomically moves the backup back onto `creds_file` (preserving `0600`) and returns `True`, or returns `False` if no backup exists. A successful restore consumes the backup — a later `write` starts a fresh no-clobber cycle from whatever was restored. This exists so a switch is reversible and `slayer` uninstall can hand the user's original login back.

## Uninstall (`teardown.py`)

`teardown.uninstall(paths, keep_accounts=...)` is the switcher's teardown service (`cli/commands/uninstall.py` is the thin Click wrapper). It restores the original login FIRST via `file_store.restore_backup` (a no-op returning `False` on macOS, where the pristine login lives in the Keychain, never touched here), then removes the venv, the `token-slayer`/`slayer` shim, and the attribution file, and — unless `keep_accounts` — the account slots/state/history/usage cache. Every removal step is tolerant of a missing item. It deliberately does NOT remove the token-slayer event-tracking hook footprint (`send-hook.sh`, hook token, detector-config, `custom.sh`, shell-rc block) — that's a separate manual step. The returned `UninstallSummary` never carries a token or credential contents.
