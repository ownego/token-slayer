import * as vscode from 'vscode';
import type { BattlefieldPanel } from '../webview/BattlefieldPanel';

const HIT_THROTTLE_MS = 5_000;

export function registerNotifications(
  context: vscode.ExtensionContext,
  panel: BattlefieldPanel,
): void {
  let lastHitAt = 0;

  const unsubscribe = panel.onBridgeEvent((m) => {
    switch (m.type) {
      case 'hit-landed': {
        const now = Date.now();
        if (now - lastHitAt < HIT_THROTTLE_MS) return;
        lastHitAt = now;
        void vscode.window.showInformationMessage(
          `token-slayer: you hit for ${m.damage.toLocaleString()} (boss ${m.bossHpAfter.toLocaleString()}/${m.bossMaxHp.toLocaleString()})`,
        );
        return;
      }
      case 'boss-defeated':
        void vscode.window.showInformationMessage(
          `token-slayer: boss defeated${m.killerHandle ? ` by @${m.killerHandle}` : ''}`,
        );
        return;
      case 'boss-spawned':
        void vscode.window.showInformationMessage(`token-slayer: new boss spawned — ${m.name}`);
        return;
      default:
        return;
    }
  });

  context.subscriptions.push({ dispose: unsubscribe });
}
