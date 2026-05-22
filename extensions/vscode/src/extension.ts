import * as vscode from 'vscode';
import { TokenSlayerClient } from './api/TokenSlayerClient';
import { AuthService } from './auth/AuthService';
import { AuthUriHandler } from './auth/UriHandler';
import { registerInstallHooks } from './commands/installHooks';
import { registerOpenBattlefield } from './commands/openBattlefield';
import { registerOpenProfile } from './commands/openProfile';
import { registerSignIn } from './commands/signIn';
import { registerSignOut } from './commands/signOut';
import { registerUninstallHooks } from './commands/uninstallHooks';
import { getServerUrl } from './config';
import { SnapshotPoller } from './realtime/SnapshotPoller';
import { registerNotifications } from './ui/Notifications';
import { registerStatusBarItem } from './ui/StatusBarItem';
import { BattlefieldPanel } from './webview/BattlefieldPanel';

export function activate(context: vscode.ExtensionContext): void {
  const serverUrl = getServerUrl();

  // Forward-declare so the client can call back into auth on 401.
  let authRef: AuthService | null = null;

  const client = new TokenSlayerClient({
    serverUrl,
    getToken: () => (authRef ? authRef.getToken() : Promise.resolve(null)),
    onUnauthorized: () => { void authRef?.handleUnauthorized(); },
    fetch: globalThis.fetch.bind(globalThis),
  });

  const auth = new AuthService({
    secrets: context.secrets,
    client,
    openBrowser: async (url) => { await vscode.env.openExternal(vscode.Uri.parse(url)); },
    serverUrl,
  });
  authRef = auth;

  const panel = new BattlefieldPanel(auth, client, serverUrl);

  // The webview's Echo bridge only runs when the user opens the sidebar.
  // The poller keeps the status bar live (boss HP, your damage, charging)
  // without requiring the panel to be open.
  const poller = new SnapshotPoller({ client });
  context.subscriptions.push({ dispose: () => poller.stop() });

  auth.onAuthChanged((s) => {
    if (s.signedIn) poller.start();
    else poller.stop();
  });
  void auth.isSignedIn().then((signedIn) => {
    if (signedIn) poller.start();
  });

  context.subscriptions.push(
    vscode.window.registerWebviewViewProvider(BattlefieldPanel.viewType, panel, {
      webviewOptions: { retainContextWhenHidden: true },
    }),
    vscode.window.registerUriHandler(
      new AuthUriHandler(async (payload) => {
        await auth.completeSignIn(payload);
        void vscode.window.showInformationMessage('token-slayer: signed in.');
      }),
    ),
  );

  registerSignIn(context, auth);
  registerSignOut(context, auth);
  registerOpenBattlefield(context);
  registerOpenProfile(context, client);
  registerInstallHooks(context, client);
  registerUninstallHooks(context, client);
  registerStatusBarItem(context, auth, panel, poller);
  registerNotifications(context, panel);
}

export function deactivate(): void {}
