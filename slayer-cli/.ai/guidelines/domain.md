# Domain Index (slayer-cli)

Detailed rules live in `.ai/domain/`:

| File | Covers |
|------|--------|
| `account-slots.md` | The `Account` slot model, on-disk layout, adding a slot (snapshot vs PKCE `--login`) |
| `switching.md` | The `switch_to()` pipeline, global-credential v1 limitation, swap history |
| `attribution.md` | Why slayer-cli ships inside token-slayer — the `active.json` contract the hook reads, `org_uuid` beacon resolution |
| `usage.md` | 5h/7d quota via rate-limit response headers, caching, TUI usage bars |
| `credstore.md` | Cross-platform active credential storage (file vs Keychain), `~/.claude.json` patching |

## Key Invariants

- **`active.json`'s `org_uuid` is never blank** — a blank value makes the token-slayer hook reject the file.
- **Tokens are never logged, printed, put in errors, or shown in the TUI** — not even at DEBUG.
- **No auto-swap in v1** — switching an account is always an explicit user action.
- **Attribution is per-event, server-side** — slayer-cli's only job is keeping the active credential and `active.json` in sync so the hook's claim is correct.
- **All credential files are `0600`, all credential directories are `0700`.**
- **v1 writes the GLOBAL credential store** (`~/.claude/.credentials.json`) — concurrent sessions share one active account; per-session isolation is future scope.
