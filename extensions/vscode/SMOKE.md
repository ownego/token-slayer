# aiorg VSCode — Smoke Test

Run this checklist before every release. The webview ↔ extension bridge
and the full auth round-trip are not covered by unit tests.

## Setup

1. Build the extension: `cd extensions/vscode && npm install && npm run build && npm run package`. A `.vsix` lands in the directory.
2. Start the Laravel app locally with Vite running: `composer run dev`. Confirm `/battlefield` loads in a regular browser.
3. In VSCode: Settings → search "aiorg" → set `aiorg.serverUrl` to `http://localhost:8000`.
4. Install the `.vsix`: Extensions panel → ⋯ menu → Install from VSIX…

## Steps

- [ ] **Cold install / sign-in:** Open the aiorg activity-bar view. You see the signed-out HTML with a "Sign in with Slack" button. Clicking it opens your default browser at `localhost:8000/auth/slack?return=ide&state=…`. Complete Slack OAuth. The browser hands off via `vscode://aiorg.aiorg/auth?…` and VSCode shows an "aiorg: signed in" notification. The sidebar reloads into the battlefield iframe.
- [ ] **Battlefield renders:** Phaser canvas is visible; you see boss + fighters. Status bar shows "$(zap) aiorg: connecting…" → "$(zap) {boss name}".
- [ ] **Hit fires native surfaces:** From a terminal, trigger a Claude Code Stop hook (or curl `/api/events`) for your user. Status bar updates to show damage and boss HP. A notification toast appears within ~1 s.
- [ ] **Throttle holds:** Fire 3 hits in <5 s. Only one notification appears.
- [ ] **Panel collapse:** Switch to the Explorer activity bar. Fire another hit. Switch back to aiorg. Status bar updated while the panel was hidden.
- [ ] **Install hooks:** Run `aiorg: Install Claude Code hooks`. Verify `~/.claude/settings.json` contains entries with `"_ns": "aiorg"` under each event. Re-run — see "already up to date".
- [ ] **Uninstall hooks:** Run `aiorg: Uninstall Claude Code hooks`. Verify the aiorg entries are gone but other entries are preserved.
- [ ] **Sign out:** Run `aiorg: Sign out`. Sidebar reverts to signed-out HTML; status bar shows "signed out"; subsequent `/api/ide/me` would 401.
- [ ] **Revoked bearer:** While signed in, revoke the user's `IdeAccessToken` row in the DB. Trigger any API hit (e.g. open the battlefield view). The extension reverts to signed-out without a crash.

## When to update

Any change to: AuthService, BattlefieldPanel, ide-bridge.js, the
`/api/ide/*` endpoints, or `bootstrap/app.php` middleware ordering.
