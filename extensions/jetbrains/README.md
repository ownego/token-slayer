# token-slayer — JetBrains / PhpStorm plugin

Companion to the [token-slayer](https://github.com/vntrungld/token-slayer) battlefield: sign in
with Slack, watch the battlefield in a tool window, get hit/boss notifications, and
install Claude Code hooks — all without leaving your JetBrains IDE.

---

## Install (for users)

You only need a JetBrains IDE — **no build tools required**.

**Requirements:** PhpStorm (or any IntelliJ-based IDE) **2024.1 or newer**.

1. Get the plugin zip `token-slayer-<version>.zip` (from your token-slayer admin /
   the releases page, or build it yourself — see below).
2. In the IDE: **Settings → Plugins → ⚙ (gear icon) → Install Plugin from Disk…**
3. Select the `.zip` and confirm. **Restart the IDE** when prompted.

### First-run setup

1. **Settings → Tools → token-slayer** → set **Server URL** to your token-slayer
   server (e.g. `https://your-token-slayer-host`). Apply.
2. Open the **token-slayer** tool window (right edge of the IDE, the ⚡ icon — or
   `Find Action` → `token-slayer: Open battlefield`).
3. Click **Sign in with Slack**. Your browser opens; complete Slack sign-in. The
   plugin receives the callback on a local loopback port and signs you in
   automatically — no OS URL-scheme setup needed.
4. The tool window loads the battlefield; the status bar shows the current boss.

### What you get

- **Battlefield tool window** — live boss + fighters, rendered via JCEF.
- **Status bar widget** — connection state and boss HP / your damage at a glance.
- **Notifications** — balloons for your hits (throttled), boss spawn, boss defeated.
- **Claude Code hooks** — `token-slayer: Install / Uninstall Claude Code hooks`
  manage the hooks in `~/.claude/settings.json`.

### Actions (via `Find Action`, double-⇧)

`token-slayer: Sign in with Slack` · `Sign out` · `Open battlefield` · `Open profile` ·
`Install Claude Code hooks` · `Uninstall Claude Code hooks`

---

## Build from source (for developers)

Requires JDK 17 and Gradle 8.9 (the wrapper handles Gradle).

```
./gradlew buildPlugin
```

The installable plugin zip lands at `build/distributions/token-slayer-<version>.zip`.

Run a sandbox IDE with the plugin for development:

```
./gradlew runIde
```

Run unit tests:

```
./gradlew test
```

See `SMOKE.md` for the manual end-to-end verification checklist.

---

## Troubleshooting

- **Tool window is empty / shows "Sign in":** you're signed out — click *Sign in with
  Slack* (or run the action). After sign-in it loads the battlefield.
- **Sign-in browser page stays open:** it's safe to close it once it shows
  "Signed in — return to your IDE".
- **`Server URL` is wrong:** Settings → Tools → token-slayer. The plugin won't connect
  until it points at a reachable token-slayer server.
