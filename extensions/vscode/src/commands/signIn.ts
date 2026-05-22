import * as vscode from 'vscode';
import type { AuthService } from '../auth/AuthService';

export function registerSignIn(context: vscode.ExtensionContext, auth: AuthService): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.signIn', async () => {
      try {
        await auth.startSignIn();
        void vscode.window.showInformationMessage(
          'token-slayer: opened your browser to sign in. Authorize Slack, then return here.',
        );
      } catch (err) {
        const message = err instanceof Error ? err.message : String(err);
        void vscode.window.showErrorMessage(`token-slayer: sign-in failed (${message})`);
      }
    }),
  );
}
