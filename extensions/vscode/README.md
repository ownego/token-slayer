# aiorg VSCode extension

Companion to the [aiorg](https://github.com/aiorg) battlefield: sign in
with Slack, watch the battlefield inside a sidebar webview, get hit/boss
notifications, and install Claude Code hooks — all without leaving the
editor.

See [docs/plans/2026-05-21-ide-frontend-embed-design.md](../../docs/plans/2026-05-21-ide-frontend-embed-design.md)
and the corresponding implementation plan for the architecture details.

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
