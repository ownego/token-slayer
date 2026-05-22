import * as vscode from 'vscode';

export function getServerUrl(): string {
  const raw = vscode.workspace.getConfiguration('token-slayer').get<string>('serverUrl', 'https://token-slayer.app');
  return raw.replace(/\/+$/, '');
}
