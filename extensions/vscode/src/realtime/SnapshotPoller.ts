import type { TokenSlayerClient } from '../api/TokenSlayerClient';
import type { BridgeMessage } from '../bridge/schema';

export interface SnapshotResponse {
  boss: { id: number; name: string; maxHp: number; currentHp: number } | null;
  yourDamage: number;
  charging: string | null;
}

export interface SnapshotPollerDeps {
  client: TokenSlayerClient;
  intervalMs?: number;
  setTimeout?: (cb: () => void, ms: number) => unknown;
  clearTimeout?: (handle: unknown) => void;
}

/**
 * Polls /api/ide/snapshot and emits BridgeMessages so the status bar can
 * stay live without the webview iframe (and its Echo bridge) being open.
 *
 * Only emits state on change to avoid spurious refreshes.
 */
export class SnapshotPoller {
  private listeners = new Set<(m: BridgeMessage) => void>();
  private handle: unknown = null;
  private running = false;
  private lastBossId: number | null | undefined = undefined;
  private lastCurrentHp: number | null = null;
  private lastYourDamage: number | null = null;
  private lastCharging: string | null | undefined = undefined;
  private lastConnection: 'connecting' | 'connected' | 'disconnected' | null = null;

  private readonly intervalMs: number;
  private readonly setTimeoutFn: (cb: () => void, ms: number) => unknown;
  private readonly clearTimeoutFn: (handle: unknown) => void;

  constructor(private readonly deps: SnapshotPollerDeps) {
    this.intervalMs = deps.intervalMs ?? 5_000;
    this.setTimeoutFn = deps.setTimeout ?? ((cb, ms) => setTimeout(cb, ms));
    this.clearTimeoutFn = deps.clearTimeout ?? ((h) => clearTimeout(h as ReturnType<typeof setTimeout>));
  }

  onBridgeEvent(listener: (m: BridgeMessage) => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  start(): void {
    if (this.running) return;
    this.running = true;
    this.emitConnection('connecting');
    void this.tick();
  }

  stop(): void {
    this.running = false;
    if (this.handle !== null) {
      this.clearTimeoutFn(this.handle);
      this.handle = null;
    }
    this.lastBossId = undefined;
    this.lastCurrentHp = null;
    this.lastYourDamage = null;
    this.lastCharging = undefined;
    this.lastConnection = null;
  }

  /** Run one fetch cycle immediately. Exposed for tests. */
  async tick(): Promise<void> {
    if (!this.running) return;

    try {
      const snap = await this.deps.client.get<SnapshotResponse>('/api/ide/snapshot');
      this.handleSnapshot(snap);
      this.emitConnection('connected');
    } catch {
      this.emitConnection('disconnected');
    }

    if (this.running) {
      this.handle = this.setTimeoutFn(() => void this.tick(), this.intervalMs);
    }
  }

  private handleSnapshot(snap: SnapshotResponse): void {
    const boss = snap.boss;

    if (boss === null) {
      // Only emit a defeat transition when we previously saw a live boss;
      // skip on the very first poll where we have no prior state.
      if (this.lastBossId !== null && this.lastBossId !== undefined) {
        this.emit({ type: 'boss-defeated', bossId: this.lastBossId, killerUserId: 0, killerHandle: null });
      }
      this.lastBossId = null;
      this.lastCurrentHp = null;
      this.lastYourDamage = null;
    } else {
      const changed =
        boss.id !== this.lastBossId ||
        boss.currentHp !== this.lastCurrentHp ||
        snap.yourDamage !== this.lastYourDamage;

      if (changed) {
        this.lastBossId = boss.id;
        this.lastCurrentHp = boss.currentHp;
        this.lastYourDamage = snap.yourDamage;
        this.emit({
          type: 'boss-snapshot',
          bossId: boss.id,
          name: boss.name,
          maxHp: boss.maxHp,
          currentHp: boss.currentHp,
          yourDamage: snap.yourDamage,
        });
      }
    }

    // Only emit when the activity actually changes. Skip the first-ever poll
    // when there is no charging activity (undefined → null is not a change a
    // user cares about).
    const chargingChanged = this.lastCharging === undefined
      ? snap.charging !== null
      : snap.charging !== this.lastCharging;
    if (chargingChanged) {
      this.lastCharging = snap.charging;
      this.emit({
        type: 'charging-updated',
        userId: 0,
        activity: snap.charging,
        startedAt: null,
      });
    } else if (this.lastCharging === undefined) {
      this.lastCharging = snap.charging;
    }
  }

  private emitConnection(state: 'connecting' | 'connected' | 'disconnected'): void {
    if (state === this.lastConnection) return;
    this.lastConnection = state;
    this.emit({ type: 'connection-state', state });
  }

  private emit(m: BridgeMessage): void {
    for (const listener of this.listeners) {
      try { listener(m); } catch { /* ignore */ }
    }
  }
}
