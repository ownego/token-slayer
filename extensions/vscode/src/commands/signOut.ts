import * as vscode from 'vscode';
import type { AuthService } from '../auth/AuthService';

export function registerSignOut(context: vscode.ExtensionContext, auth: AuthService): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.signOut', async () => {
      await auth.signOut();
      void vscode.window.showInformationMessage('token-slayer: signed out.');
    }),
  );
}
