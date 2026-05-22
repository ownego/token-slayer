import * as vscode from 'vscode';

export class AuthUriError extends Error {}

export interface AuthUriPayload {
  token: string;
  state: string;
}

export function parseAuthUri(uri: string): AuthUriPayload {
  const parsed = new URL(uri);

  if (parsed.pathname !== '/auth') {
    throw new AuthUriError(`unexpected path: ${parsed.pathname}`);
  }

  const token = parsed.searchParams.get('token');
  const state = parsed.searchParams.get('state');

  if (!token) throw new AuthUriError('missing token');
  if (!state) throw new AuthUriError('missing state');

  return { token, state };
}

export class AuthUriHandler implements vscode.UriHandler {
  constructor(private readonly onAuth: (payload: AuthUriPayload) => Promise<void>) {}

  async handleUri(uri: vscode.Uri): Promise<void> {
    try {
      const payload = parseAuthUri(uri.toString(true));
      await this.onAuth(payload);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      void vscode.window.showErrorMessage(`token-slayer: sign-in link invalid (${message})`);
    }
  }
}
