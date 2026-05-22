import * as vscode from 'vscode';
import type { TokenSlayerClient } from '../api/TokenSlayerClient';

export function registerOpenProfile(
  context: vscode.ExtensionContext,
  client: TokenSlayerClient,
): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.openProfile', async () => {
      try {
        const { url } = await client.post<{ url: string }>(
          '/api/ide/auth/session-url',
          { path: '/profile' },
        );
        await vscode.env.openExternal(vscode.Uri.parse(url));
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        void vscode.window.showErrorMessage(`token-slayer: open profile failed (${message})`);
      }
    }),
  );
}
