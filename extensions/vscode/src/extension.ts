import * as vscode from 'vscode';

export function activate(context: vscode.ExtensionContext): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('aiorg.signIn', () => {
      void vscode.window.showInformationMessage('aiorg: sign-in not yet wired');
    }),
  );
}

export function deactivate(): void {}
