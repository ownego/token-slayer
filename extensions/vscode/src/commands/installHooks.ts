import * as vscode from 'vscode';
import type { TokenSlayerClient } from '../api/TokenSlayerClient';
import { mergeHooks, type HookConfig } from '../hooks/HookManager';
import { InvalidSettingsError, SettingsFile } from '../hooks/SettingsFile';

export function registerInstallHooks(
  context: vscode.ExtensionContext,
  client: TokenSlayerClient,
  settingsFile = new SettingsFile(),
): void {
  context.subscriptions.push(
    vscode.commands.registerCommand('token-slayer.installHooks', async () => {
      try {
        const config = await client.get<HookConfig>('/api/ide/hook-config');
        const existing = await settingsFile.read();
        const next = mergeHooks(existing, config);

        if (JSON.stringify(existing) === JSON.stringify(next)) {
          void vscode.window.showInformationMessage('token-slayer hooks already up to date.');
          return;
        }

        await settingsFile.write(next);
        void vscode.window.showInformationMessage(
          `token-slayer hooks installed in ${settingsFile.filePath}`,
        );
      } catch (err) {
        if (err instanceof InvalidSettingsError) {
          const open = 'Open file';
          const choice = await vscode.window.showErrorMessage(
            `token-slayer: ${settingsFile.filePath} is not valid JSON. Fix it and retry.`,
            open,
          );
          if (choice === open) {
            await vscode.window.showTextDocument(vscode.Uri.file(settingsFile.filePath));
          }
          return;
        }
        const message = err instanceof Error ? err.message : String(err);
        void vscode.window.showErrorMessage(`token-slayer: install failed (${message})`);
      }
    }),
  );
}
