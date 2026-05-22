import * as vscode from 'vscode';
import type { TokenSlayerClient } from '../api/TokenSlayerClient';
import { removeHooks } from '../hooks/HookManager';
import { InvalidSettingsError, SettingsFile } from '../hooks/SettingsFile';

export function registerUninstallHooks(
  context: vscode.ExtensionContext,
  client: TokenSlayerClient,
  settingsFile = new SettingsFile(),
): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.uninstallHooks', async () => {
      try {
        const config = await client.get<{ namespace: string }>('/api/ide/hook-config');
        const existing = await settingsFile.read();
        const next = removeHooks(existing, config.namespace);

        if (JSON.stringify(existing) === JSON.stringify(next)) {
          void vscode.window.showInformationMessage('No token-slayer hooks installed.');
          return;
        }

        await settingsFile.write(next);
        void vscode.window.showInformationMessage(
          `token-slayer hooks removed from ${settingsFile.filePath}`,
        );
      } catch (err) {
        if (err instanceof InvalidSettingsError) {
          void vscode.window.showErrorMessage(
            `token-slayer: ${settingsFile.filePath} is not valid JSON.`,
          );
          return;
        }
        const message = err instanceof Error ? err.message : String(err);
        void vscode.window.showErrorMessage(`token-slayer: uninstall failed (${message})`);
      }
    }),
  );
}
