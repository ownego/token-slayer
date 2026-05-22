import * as vscode from 'vscode';

export function registerOpenBattlefield(context: vscode.ExtensionContext): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.openBattlefield', () => {
      void vscode.commands.executeCommand('workbench.view.extension.token-slayer');
    }),
  );
}
