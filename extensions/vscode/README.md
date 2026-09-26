# token-slayer VSCode extension

Companion to the [token-slayer](https://github.com/ownego/token-slayer) battlefield. With it you can sign in
with Slack, watch the battlefield inside a sidebar webview, get hit/boss
notifications, and install Claude Code hooks without leaving the editor.

## How it talks to the server

- Sign-in exchanges a one-time token at `POST /api/ide/auth/exchange` for an IDE bearer (`IdeAccessToken`). Every other call is authenticated by `ide.bearer`: `GET /api/ide/me`, `GET /api/ide/snapshot`, `GET /api/ide/hook-config`, `POST /api/ide/auth/session-url`, `POST /api/ide/auth/revoke` (`routes/api.php`, controllers in `app/Http/Controllers/Api/Ide/`).
- The webview loads the battlefield in embed mode, which loads `resources/js/ide-bridge.js`. The bridge listens to Reverb broadcasts and posts `hit-landed` / `boss-defeated` / `boss-spawned` / `charging-updated` / `connection-state` messages to the extension host. A renamed broadcast or payload key must be updated there too (see `.ai/domain/broadcasting.md` in the main repo).

## Commands

`token-slayer: Sign in with Slack` · `Sign out` · `Install Claude Code hooks` · `Uninstall Claude Code hooks` · `Open battlefield` · `Open profile`

## Build

```
npm install
npm run build
```

The build artifact lands at `dist/extension.js`. Use `npm run package` (with
`@vscode/vsce` installed) to produce a `.vsix`.

## Test

```
npm test
```

See `SMOKE.md` for the manual end-to-end verification checklist.
