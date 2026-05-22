import { describe, it, expect, vi, beforeEach } from 'vitest';
import { SnapshotPoller, type SnapshotResponse } from '../realtime/SnapshotPoller';
import type { BridgeMessage } from '../bridge/schema';

function makeClient(responses: SnapshotResponse[] | Error[]) {
  let i = 0;
  return {
    get: vi.fn(async () => {
      const r = responses[Math.min(i, responses.length - 1)];
      i++;
      if (r instanceof Error) throw r;
      return r;
    }),
  } as any;
}

function record(): { events: BridgeMessage[]; on: (m: BridgeMessage) => void } {
  const events: BridgeMessage[] = [];
  return { events, on: (m) => events.push(m) };
}

describe('SnapshotPoller', () => {
  beforeEach(() => vi.clearAllMocks());

  it('emits connecting on start, then connected + boss-snapshot after first tick', async () => {
    const client = makeClient([{
      boss: { id: 1, name: 'Boss 1', maxHp: 1000, currentHp: 800 },
      yourDamage: 100,
      charging: null,
    }]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();

    expect(events).toEqual([
      { type: 'connection-state', state: 'connecting' },
      { type: 'boss-snapshot', bossId: 1, name: 'Boss 1', maxHp: 1000, currentHp: 800, yourDamage: 100 },
      { type: 'connection-state', state: 'connected' },
    ]);
  });

  it('emits disconnected on fetch failure', async () => {
    const client = makeClient([new Error('boom')]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();

    expect(events).toContainEqual({ type: 'connection-state', state: 'disconnected' });
    expect(events.find((e) => e.type === 'boss-snapshot')).toBeUndefined();
  });

  it('deduplicates identical snapshots so the status bar does not re-render', async () => {
    const same = {
      boss: { id: 2, name: 'Boss 2', maxHp: 500, currentHp: 500 },
      yourDamage: 0,
      charging: null,
    } satisfies SnapshotResponse;
    const client = makeClient([same, same, same]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();
    await poller.tick();
    await poller.tick();

    const snapshots = events.filter((e) => e.type === 'boss-snapshot');
    expect(snapshots).toHaveLength(1);
  });

  it('emits boss-defeated only on the transition from a live boss to null', async () => {
    const client = makeClient([
      { boss: null, yourDamage: 0, charging: null },
      { boss: { id: 3, name: 'Boss 3', maxHp: 100, currentHp: 100 }, yourDamage: 0, charging: null },
      { boss: null, yourDamage: 0, charging: null },
    ]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();
    await poller.tick();
    await poller.tick();

    const defeats = events.filter((e) => e.type === 'boss-defeated');
    expect(defeats).toHaveLength(1);
    expect(defeats[0]).toMatchObject({ type: 'boss-defeated', bossId: 3 });
  });

  it('emits charging-updated only when activity string changes', async () => {
    const client = makeClient([
      { boss: null, yourDamage: 0, charging: null },
      { boss: null, yourDamage: 0, charging: 'writing code' },
      { boss: null, yourDamage: 0, charging: 'writing code' },
      { boss: null, yourDamage: 0, charging: null },
    ]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();
    await poller.tick();
    await poller.tick();
    await poller.tick();

    const charging = events.filter((e) => e.type === 'charging-updated');
    expect(charging.map((c) => c.type === 'charging-updated' && c.activity)).toEqual([
      'writing code',
      null,
    ]);
  });

  it('stop() clears state so a subsequent start re-emits initial snapshot', async () => {
    const snap: SnapshotResponse = {
      boss: { id: 4, name: 'Boss 4', maxHp: 200, currentHp: 150 },
      yourDamage: 10,
      charging: null,
    };
    const client = makeClient([snap, snap]);
    const poller = new SnapshotPoller({ client, setTimeout: () => null, clearTimeout: () => {} });
    const { events, on } = record();
    poller.onBridgeEvent(on);

    poller.start();
    await poller.tick();
    poller.stop();

    poller.start();
    await poller.tick();

    const snapshots = events.filter((e) => e.type === 'boss-snapshot');
    expect(snapshots).toHaveLength(2);
  });
});
