# slayer-cli

slayer-cli manages multiple Claude Code account slots on one machine and
switches Claude Code's active login between them. It's the client half of
token-slayer's multi-account support — the server-side event tracking
(the `send-hook.sh` shell hook that posts to `/api/events` for the
battlefield) is installed separately by the main token-slayer installer;
slayer-cli itself only handles accounts and switching.

## What It Does

- **Manage accounts:** add, list, remove, switch, and alias Claude account slots.
- **Pull provisioned accounts:** `setup` fetches accounts an admin provisioned for you.
- **Detect your existing login:** `detect-base` registers whatever account you're already logged into as a slot.
- **Track usage:** view each account's quota/utilization.
- **Interactive TUI:** `token-slayer` with no arguments launches a Textual UI to browse accounts and switch between them.

## Installation

slayer-cli isn't published to PyPI — it's installed as part of the main
token-slayer setup:

```bash
curl -fsSL https://token-slayer.ownego.com/install | sh
```

This installs a private venv under `~/.config/<namespace>/venv`, a
`token-slayer`/`slayer` shim on your `PATH` (both point at the same CLI),
the event-tracking hook (`send-hook.sh`), and registers Claude Code hooks
in `~/.claude/settings.json`. Re-running the installer is the upgrade path
(`token-slayer update` does this for you).

If your machine already has Claude Code logged in, the installer
automatically registers that login as a base account slot
(`detect-base`) — the account you're currently using is set up for you
the moment the install finishes, nothing else to do.

**First run only:** `token-slayer`/`slayer` won't be found in the same
terminal you just ran the install in — the installer adds `~/.local/bin`
to `PATH` via `~/.zshrc` (macOS default shell) or `~/.bashrc`, but your
current shell doesn't reload that automatically. Open a new terminal, or
run `source ~/.zshrc` / `source ~/.bashrc`.

**macOS:** the first `switch` (or `setup`) pops a Keychain prompt asking
for your login password/Touch ID — that's macOS asking permission to
store the credential, not the command hanging; choose *Always Allow* to
avoid repeat prompts. Also, on a brand-new Mac `python3` may be an Xcode
stub that triggers its own "Install Command Line Developer Tools?"
dialog the first time anything actually invokes it — run
`xcode-select -p` first (empty output means run `xcode-select --install`)
so the account-switcher venv sets up cleanly.

## Usage

### Adding a new personal account

1. Log into that account in Claude Code itself (run `claude`, then `/login`).
2. Run `token-slayer add NAME` — this snapshots whichever account Claude
   Code is now logged into.

For an **org account**, don't use `add` at all — contact an admin to
provision it for you, then run `token-slayer setup` (see below).

### Account commands

```bash
# List all account slots, marking the active one
token-slayer list

# Print just the active slot's name and email/org
token-slayer current

# Add a slot from the machine's CURRENTLY logged-in Claude Code session
token-slayer add work

# Pull accounts an admin provisioned for you and configure Claude Code
token-slayer setup

# Register the machine's current Claude login as a base slot (usually automatic at install)
token-slayer detect-base

# Switch the active Claude account
token-slayer switch work

# Switch bypassing rotation-capture (recovery when the outgoing slot's live credentials are broken)
token-slayer force-switch work

# Set/clear a slot's alias
token-slayer alias work@company.com w

# Remove a slot (falls back to the most-recently-used remaining account if any are left)
token-slayer remove work

# Print version, namespace, active account, and credential status
token-slayer status
```

### Interactive TUI

```bash
token-slayer          # launches the TUI directly (no subcommand)
token-slayer tui       # same, explicit
```

- `↑`/`↓` — move between accounts
- `Enter` — switch to the highlighted account
- `r` — force a live usage refresh (bypasses the cache)
- `q` — quit

### Full Help

```bash
token-slayer --help
token-slayer <command> --help
```

## Uninstall / Teardown

```bash
token-slayer uninstall                  # prompts for confirmation
token-slayer uninstall --yes            # skip the confirmation prompt
token-slayer uninstall --keep-accounts  # remove the switcher but keep your stored account slots
```

`uninstall` is safe and reversible: it restores your original Claude login
from the pristine `.slayer-bak` backup that was captured before slayer-cli's
first switch (Linux/Windows only — macOS keeps the original in the system
Keychain and is left untouched), then removes the switcher's venv, the
`token-slayer`/`slayer` shim, and the attribution file. Unless
`--keep-accounts` is given, it also removes your stored account slots,
switch state, swap history, and usage cache. It never touches the
token-slayer event-tracking hook footprint (`send-hook.sh`, hook token,
detector-config, `custom.sh`, shell-rc PATH block) — tearing that down is a
separate manual step.

## How attribution works

Every `switch`, `setup`, and `detect-base` call reconciles two files the
event-tracking hook reads, in priority order:

1. `~/.config/<namespace>/account-provider/active.json` — highest priority (`account_source: provider`).
2. `~/.claude.json`'s `oauthAccount` block — fallback if the provider file is missing/stale.

If an account has no resolvable `org_uuid`, the provider file is removed
rather than left stale, so the hook degrades to a lower-priority source
instead of misattributing usage to the wrong account.

## Development

### Setup

```bash
cd slayer-cli
python3 -m venv .venv
source .venv/bin/activate
pip install -e ".[dev]"
```

### Run Tests

```bash
python -m pytest -q
```

### Run the CLI Locally

```bash
python -m slayer_cli --help
```

## Build & Deploy

```bash
./build.sh
```

Builds the wheel and copies it to `storage/app/dist/slayer_cli-latest.whl`
(served by the token-slayer server at `/dist/slayer_cli-latest.whl`, which
the install script downloads under a PEP 427-valid temp filename —
`slayer_cli-latest.whl` itself isn't a valid wheel filename).

**Bump the version in both `pyproject.toml` and `src/slayer_cli/version.py`
before every deploy.** The install script force-reinstalls the package
code every run, but `pip install --upgrade` elsewhere is a no-op if the
wheel's own metadata version hasn't changed — an unbumped version can
silently ship no code changes at all despite a "successful" reinstall.

## License

Internal — token-slayer suite.
