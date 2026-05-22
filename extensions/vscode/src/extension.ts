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

  // The bridge that updates the status bar only runs inside the webview
  // iframe — and the iframe doesn't load until the sidebar view is opened
  // at least once. Focusing the view forces VSCode to call
  // `resolveWebviewView`, which loads the iframe and starts the bridge.
  const revealPanel = async (): Promise<void> => {
    try {
      await vscode.commands.executeCommand(`${BattlefieldPanel.viewType}.focus`);
    } catch {
      // .focus auto-commands aren't always exposed in older VSCode builds;
      // fall back to focusing the activity bar container.
      try {
        await vscode.commands.executeCommand('workbench.view.extension.token-slayer');
      } catch {
        // ignore — the user can open the sidebar manually.
      }
    }
  };

  context.subscriptions.push(
    vscode.window.registerWebviewViewProvider(BattlefieldPanel.viewType, panel, {
      webviewOptions: { retainContextWhenHidden: true },
    }),
    vscode.window.registerUriHandler(
      new AuthUriHandler(async (payload) => {
        await auth.completeSignIn(payload);
        void vscode.window.showInformationMessage('token-slayer: signed in.');
        void revealPanel();
      }),
    ),
  );

  registerSignIn(context, auth);
  registerSignOut(context, auth);
  registerOpenBattlefield(context);
  registerOpenProfile(context, client);
  registerInstallHooks(context, client);
  registerUninstallHooks(context, client);
  registerStatusBarItem(context, auth, panel);
  registerNotifications(context, panel);

  // If the user is already signed in (bearer persists in SecretStorage
  // across restarts), reveal the panel on activation so the bridge runs
  // and the status bar isn't stuck on "connecting…" forever.
  void auth.isSignedIn().then(async (signedIn) => {
    if (signedIn) await revealPanel();
  });
}

export function deactivate(): void {}
