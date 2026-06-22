# token-slayer JetBrains — Smoke Test

Run this checklist before every release. The JCEF ↔ plugin bridge, the
`jetbrains://` deep-link round-trip, and the full Slack auth flow are not
covered by unit tests.

## Setup

1. Build + launch a sandbox IDE with the plugin:
   ```bash
   cd extensions/jetbrains
   export SDKMAN_DIR="$HOME/.sdkman"
   export JAVA_HOME="$HOME/.sdkman/candidates/java/current"
   export PATH="$HOME/.sdkman/candidates/java/current/bin:$HOME/.sdkman/candidates/gradle/current/bin:$PATH"
   ./gradlew runIde
   ```
   A sandbox PhpStorm launches with the **token-slayer** tool window docked on the right.
2. Point the plugin at staging: **Settings → Tools → token-slayer** → set **Server URL** to `https://ts.tungot.dev` (the deployed staging server with the Task 9 `client=jetbrains` redirect live).
3. Confirm `/battlefield` loads in a regular browser at that server first.

## Steps

- [ ] **Cold sign-in (spec open item #1 — `jetbrains://` host prefix):** Open the token-slayer tool window → signed-out HTML with a "Sign in with Slack" button (or run **Find Action → `token-slayer: Sign in with Slack`**). Your default browser opens at `…/auth/slack?return=ide&client=jetbrains&state=…`. Complete Slack OAuth. The browser must hand back to PhpStorm via `jetbrains://php-storm/token-slayer?token=…&state=…`, a "signed in" balloon appears, and the tool window reloads into the battlefield.
  - If the OS does **not** route the deep link, note the actual product prefix from `runIde` logs / JetBrains docs and update the single string in `SlackController::redirectToIde` (Task 9), redeploy, and re-test.
- [ ] **Battlefield renders (spec open item #2 — JCEF framing):** The Phaser canvas renders inside JCEF (boss + fighters visible). No blank frame and no CSP `frame-ancestors` error in the JCEF devtools console. If blocked, add the JCEF origin scheme to `EstablishIdeSession::relaxFramingFor()` and re-test.
- [ ] **Status bar — connecting → boss:** Status bar widget shows `↻ token-slayer…` (connecting) → `⚡ {boss name}` once a snapshot arrives. Hover shows boss HP % and "Your damage".
- [ ] **Hit fires native surfaces:** Trigger a Claude Code Stop hook (or `curl` `/api/events`) for your user. Status bar updates to the new boss HP and your damage. A balloon notification appears within ~1 s.
- [ ] **Throttle holds:** Fire 3 hits in <5 s. Only one hit notification appears.
- [ ] **Status updates while tool window hidden:** Collapse / hide the token-slayer tool window. Fire another hit. Re-open — the status bar reflects the hit landed while hidden.
- [ ] **Install hooks:** Run **Find Action → `token-slayer: Install Claude Code hooks`**. Verify `~/.claude/settings.json` contains entries with `"_ns": "token_slayer"` under each event. Re-run → "already up to date".
- [ ] **Uninstall hooks:** Run **`token-slayer: Uninstall Claude Code hooks`**. The token-slayer entries are gone; any foreign / pre-existing entries are preserved.
- [ ] **Sign out:** Run **`token-slayer: Sign out`**. Tool window reverts to signed-out HTML; status bar shows the sign-in prompt; subsequent `/api/ide/me` would 401.
- [ ] **Revoked bearer:** While signed in, revoke the user's `IdeAccessToken` row in the DB. Trigger any API hit (e.g. re-open the battlefield). The plugin reverts to signed-out without a crash (401 → `handleUnauthorized`).

## When to update

Any change to: `AuthService`, `BattlefieldToolWindow`, the JS relay
(`window.__tokenSlayerRelay`), `TokenSlayerService` wiring, the `/api/ide/*`
endpoints, `SlackController::redirectToIde`, or `bootstrap/app.php`
middleware ordering.
