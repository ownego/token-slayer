import * as vscode from 'vscode';
import type { AuthService } from '../auth/AuthService';
import type { TokenSlayerClient } from '../api/TokenSlayerClient';
import { parseBridgeMessage, type BridgeMessage } from '../bridge/schema';
import {
  errorHtml,
  iframeWrapperHtml,
  loadingHtml,
  makeNonce,
  signedOutHtml,
} from './templates';

export class BattlefieldPanel implements vscode.WebviewViewProvider {
  public static readonly viewType = 'token-slayer.battlefield';

  private view: vscode.WebviewView | null = null;
  private bridgeListeners = new Set<(m: BridgeMessage) => void>();

  constructor(
    private readonly auth: AuthService,
    private readonly client: TokenSlayerClient,
    private readonly serverUrl: string,
  ) {
    auth.onAuthChanged(() => { void this.refresh(); });
  }

  onBridgeEvent(listener: (m: BridgeMessage) => void): () => void {
    this.bridgeListeners.add(listener);
    return () => this.bridgeListeners.delete(listener);
  }

  async resolveWebviewView(view: vscode.WebviewView): Promise<void> {
    this.view = view;

    view.webview.options = {
      enableScripts: true,
      enableCommandUris: false,
      localResourceRoots: [],
    };

    view.webview.onDidReceiveMessage((raw) => this.handleMessage(raw));

    await this.refresh();
  }

  private handleMessage(raw: unknown): void {
    if (typeof raw === 'object' && raw !== null) {
      const m = raw as { type?: string };
      if (m.type === 'sign-in-requested') {
        void vscode.commands.executeCommand('token-slayer.signIn');
        return;
      }
      if (m.type === 'retry-requested') {
        void this.refresh();
        return;
      }
    }

    const parsed = parseBridgeMessage(raw);
    if (!parsed) return;

    for (const listener of this.bridgeListeners) {
      try { listener(parsed); } catch { /* ignore */ }
    }
  }

  private async refresh(): Promise<void> {
    if (!this.view) return;
    const nonce = makeNonce();

    if (!(await this.auth.isSignedIn())) {
      this.view.webview.html = signedOutHtml(nonce);
      return;
    }

    this.view.webview.html = loadingHtml(nonce);

    try {
      const result = await this.client.post<{ url: string }>(
        '/api/ide/auth/session-url',
        { path: '/battlefield?embed=ide' },
      );

      const serverOrigin = new URL(this.serverUrl).origin;
      this.view.webview.html = iframeWrapperHtml(makeNonce(), result.url, serverOrigin);
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      this.view.webview.html = errorHtml(makeNonce(), message);
    }
  }
}
