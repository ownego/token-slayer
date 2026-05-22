import * as vscode from 'vscode';
import type { AuthService } from '../auth/AuthService';
import type { BattlefieldPanel } from '../webview/BattlefieldPanel';

interface StatusState {
  signedIn: boolean;
  connection: 'connecting' | 'connected' | 'reconnecting' | 'disconnected';
  boss: { name: string; maxHp: number; currentHp: number | null } | null;
  lastHit: { damage: number; at: Date } | null;
  charging: string | null;
  yourDamage: number;
}

export function registerStatusBarItem(
  context: vscode.ExtensionContext,
  auth: AuthService,
  panel: BattlefieldPanel,
): void {
  const item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
  context.subscriptions.push(item);

  const state: StatusState = {
    signedIn: false,
    connection: 'connecting',
    boss: null,
    lastHit: null,
    charging: null,
    yourDamage: 0,
  };

  const warnBg = new vscode.ThemeColor('statusBarItem.warningBackground');
  const errorBg = new vscode.ThemeColor('statusBarItem.errorBackground');

  const refresh = () => {
    item.text = iconFor(state);
    item.backgroundColor = backgroundFor(state, warnBg, errorBg);
    item.command = state.signedIn ? 'aiorg.openBattlefield' : 'aiorg.signIn';
    item.tooltip = buildTooltip(state);
    item.show();
  };

  auth.onAuthChanged((next) => {
    state.signedIn = next.signedIn;
    if (!next.signedIn) {
      state.connection = 'connecting';
      state.boss = null;
      state.lastHit = null;
      state.charging = null;
      state.yourDamage = 0;
    }
    refresh();
  });

  panel.onBridgeEvent((m) => {
    switch (m.type) {
      case 'connection-state':
        state.connection = m.state;
        break;
      case 'boss-spawned':
        state.boss = { name: m.name, maxHp: m.maxHp, currentHp: m.maxHp };
        state.yourDamage = 0;
        break;
      case 'boss-snapshot':
        state.boss = { name: m.name, maxHp: m.maxHp, currentHp: m.currentHp };
        state.yourDamage = m.yourDamage;
        break;
      case 'boss-defeated':
        state.boss = null;
        state.yourDamage = 0;
        break;
      case 'hit-landed':
        state.lastHit = { damage: m.damage, at: new Date() };
        state.yourDamage += m.damage;
        if (state.boss === null || state.boss.maxHp !== m.bossMaxHp) {
          state.boss = { name: state.boss?.name ?? 'boss', maxHp: m.bossMaxHp, currentHp: m.bossHpAfter };
        } else {
          state.boss.currentHp = m.bossHpAfter;
        }
        break;
      case 'charging-updated':
        state.charging = m.activity;
        break;
      default:
        return;
    }
    refresh();
  });

  void auth.isSignedIn().then((signedIn) => {
    state.signedIn = signedIn;
    refresh();
  });
}

function iconFor(s: StatusState): string {
  if (!s.signedIn) return '$(zap)';
  if (s.connection === 'connecting' || s.connection === 'reconnecting') return '$(sync~spin)';
  if (s.connection === 'disconnected') return '$(warning)';
  if (s.charging) return '$(zap)';
  return '$(zap)';
}

function backgroundFor(
  s: StatusState,
  warn: vscode.ThemeColor,
  error: vscode.ThemeColor,
): vscode.ThemeColor | undefined {
  if (!s.signedIn) return warn;
  if (s.connection === 'disconnected') return error;
  return undefined;
}

function buildTooltip(s: StatusState): vscode.MarkdownString {
  const md = new vscode.MarkdownString(undefined, true);
  md.isTrusted = true;
  md.supportThemeIcons = true;

  // Header
  md.appendMarkdown(`#### aiorg &nbsp;&nbsp; ${statusBadge(s)}\n\n`);

  if (!s.signedIn) {
    md.appendMarkdown('Sign in with Slack to see live boss and fighter data, get hit notifications, and install Claude Code hooks.\n\n');
    md.appendMarkdown('[$(sign-in) &nbsp; Sign in with Slack &nbsp;](command:aiorg.signIn)');
    return md;
  }

  md.appendMarkdown('---\n\n');

  if (s.boss && s.boss.currentHp !== null) {
    const hpPct = clampPct(s.boss.currentHp / s.boss.maxHp);
    appendQuotaSection(md,
      escapeMd(s.boss.name),
      `${Math.round(hpPct * 100)}%`,
      'HP left',
      `${s.boss.currentHp.toLocaleString()} / ${s.boss.maxHp.toLocaleString()}`,
      hpPct,
    );

    const totalDamage = s.boss.maxHp - s.boss.currentHp;
    const yourPct = totalDamage > 0 ? clampPct(s.yourDamage / totalDamage) : 0;
    appendQuotaSection(md,
      'Your damage',
      `${Math.round(yourPct * 100)}%`,
      'of damage',
      `${s.yourDamage.toLocaleString()} / ${totalDamage.toLocaleString()}`,
      yourPct,
    );
  } else if (s.boss) {
    appendQuotaSection(md,
      escapeMd(s.boss.name),
      s.boss.maxHp.toLocaleString(),
      'HP',
      'awaiting first hit',
      0,
    );
  } else {
    md.appendMarkdown('_No boss is currently spawned._\n\n');
  }

  md.appendMarkdown('---\n\n');

  if (s.charging) {
    md.appendMarkdown(`**Charging:** \`${escapeMd(s.charging)}\`\n\n`);
  }
  if (s.lastHit) {
    md.appendMarkdown(`**Last hit:** ${s.lastHit.damage.toLocaleString()} damage · ${formatAgo(Date.now() - s.lastHit.at.getTime())}\n\n`);
  }

  md.appendMarkdown('---\n\n');
  md.appendMarkdown('[$(rocket) Open](command:aiorg.openBattlefield) &nbsp; · &nbsp; ');
  md.appendMarkdown('[$(tools) Install hooks](command:aiorg.installHooks) &nbsp; · &nbsp; ');
  md.appendMarkdown('[$(sign-out) Sign out](command:aiorg.signOut)');
  return md;
}

function appendQuotaSection(
  md: vscode.MarkdownString,
  title: string,
  bigValue: string,
  bigSuffix: string,
  rightDetail: string,
  fraction: number,
): void {
  md.appendMarkdown(`##### ${title}\n\n`);
  md.appendMarkdown(`**${bigValue}** ${bigSuffix} &nbsp; · &nbsp; \`${rightDetail}\`\n\n`);
  md.appendMarkdown(`\`${progressBar(fraction)}\`\n\n`);
}

function statusBadge(s: StatusState): string {
  if (!s.signedIn) return '$(circle-slash) Signed out';
  switch (s.connection) {
    case 'connected': return '$(pass-filled) Connected';
    case 'connecting': return '$(sync~spin) Connecting…';
    case 'reconnecting': return '$(sync~spin) Reconnecting…';
    case 'disconnected': return '$(error) Disconnected';
  }
}

const BAR_WIDTH = 30;

function progressBar(fraction: number): string {
  const filled = Math.round(clampPct(fraction) * BAR_WIDTH);
  return '█'.repeat(filled) + '░'.repeat(BAR_WIDTH - filled);
}

function escapeMd(s: string): string {
  return s.replace(/[\\`*_{}\[\]()#+\-.!|<>]/g, (c) => `\\${c}`);
}

function clampPct(n: number): number {
  if (!Number.isFinite(n) || n < 0) return 0;
  if (n > 1) return 1;
  return n;
}

function formatAgo(ms: number): string {
  const s = Math.max(0, Math.round(ms / 1000));
  if (s < 60) return `${s}s ago`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m}m ago`;
  const h = Math.round(m / 60);
  return `${h}h ago`;
}
