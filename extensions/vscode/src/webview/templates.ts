export function signedOutHtml(nonce: string): string {
  return `<!DOCTYPE html>
<html><head>
  <meta http-equiv="Content-Security-Policy"
    content="default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-${nonce}';">
</head><body style="font-family:sans-serif;padding:1rem;color:var(--vscode-foreground)">
  <h2>aiorg</h2>
  <p>Sign in with Slack to see your battlefield, get hit notifications, and install Claude Code hooks.</p>
  <button id="signin" style="padding:.5rem 1rem">Sign in with Slack</button>
  <script nonce="${nonce}">
    const api = acquireVsCodeApi();
    document.getElementById('signin').addEventListener('click', () => api.postMessage({ type: 'sign-in-requested' }));
  </script>
</body></html>`;
}

export function loadingHtml(nonce: string): string {
  return `<!DOCTYPE html>
<html><head>
  <meta http-equiv="Content-Security-Policy"
    content="default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-${nonce}';">
</head><body style="font-family:sans-serif;padding:1rem;color:var(--vscode-foreground)">
  <p>Loading battlefield…</p>
</body></html>`;
}

export function errorHtml(nonce: string, message: string): string {
  return `<!DOCTYPE html>
<html><head>
  <meta http-equiv="Content-Security-Policy"
    content="default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-${nonce}';">
</head><body style="font-family:sans-serif;padding:1rem;color:var(--vscode-foreground)">
  <h3>Couldn't load aiorg</h3>
  <p style="color:var(--vscode-errorForeground)">${escapeHtml(message)}</p>
  <button id="retry">Retry</button>
  <script nonce="${nonce}">
    const api = acquireVsCodeApi();
    document.getElementById('retry').addEventListener('click', () => api.postMessage({ type: 'retry-requested' }));
  </script>
</body></html>`;
}

export function iframeWrapperHtml(nonce: string, signedUrl: string, serverOrigin: string): string {
  return `<!DOCTYPE html>
<html><head>
  <meta http-equiv="Content-Security-Policy"
    content="default-src 'none'; frame-src ${serverOrigin}; script-src 'nonce-${nonce}'; style-src 'unsafe-inline';">
  <style>
    html, body { margin: 0; padding: 0; height: 100vh; width: 100%; overflow: hidden; background: #0f172a; }
    iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; display: block; }
  </style>
</head><body>
  <iframe id="aiorg" src="${escapeHtml(signedUrl)}"></iframe>
  <script nonce="${nonce}">
    const api = acquireVsCodeApi();
    window.addEventListener('message', (event) => {
      if (event.source !== document.getElementById('aiorg').contentWindow) return;
      if (event.origin !== '${serverOrigin}') return;
      api.postMessage(event.data);
    });
  </script>
</body></html>`;
}

function escapeHtml(s: string): string {
  return s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]!));
}

export function makeNonce(): string {
  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
