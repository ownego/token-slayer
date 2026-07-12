# slayer-cli

slayer-cli is a command-line and interactive tool for managing Claude account slots locally. It connects to token-slayer's real-time battlefield visualization, sending usage events as you work.

## What It Does

- **Manage accounts:** Register, list, remove, and switch between Claude API accounts.
- **Track usage:** View quota and token consumption for each account.
- **Real-time events:** Send usage data to token-slayer for visualization on the battlefield.
- **Interactive TUI:** Use `slayer tui` for a graphical interface to manage accounts and view stats.

## Installation

```bash
pip install slayer-cli
```

This installs two console commands: `slayer` and `token-slayer` (both point to the same CLI).

## Usage

### Quick Commands

```bash
# Show current active account
slayer current

# List all registered accounts
slayer list

# Add a new account (OAuth flow)
slayer add --name "Work" --email dev@company.com

# Switch to an account
slayer switch work-1

# View usage and quota
slayer status

# Launch interactive TUI
slayer tui

# Install hooks into Claude Code
slayer install-hooks
```

### Interactive TUI

```bash
slayer tui
```

Launches a Textual-based interface where you can:
- View all registered accounts
- See real-time usage stats
- Switch the active account with arrow keys and Enter
- Refresh quota from Anthropic

### Full Help

```bash
slayer --help
slayer <command> --help
```

## Integration with token-slayer

After installing and registering accounts, `slayer install-hooks` sets up background hooks in your Claude Code environment. These hooks:

1. Detect when you use Claude Code.
2. Send usage events to token-slayer's `/api/events` endpoint.
3. Attribute events to the currently active account (org UUID).
4. Display real-time damage on the token-slayer battlefield.

## Development

### Setup

```bash
git clone <repo>
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

To build the wheel distribution for installation on dev machines:

```bash
./build.sh
```

This builds the wheel and places it at `storage/app/dist/slayer_cli-latest.whl`. The token-slayer server serves this wheel over HTTP at `/dist/slayer_cli-latest.whl`, allowing `token-slayer update` on dev machines to fetch and install the latest version.

When deploying a new version to staging, ensure the freshly built wheel is included in the rsync to the `tungot` host alongside PHP changes, so the update mechanism picks up the new version.

## License

Internal — token-slayer suite.
