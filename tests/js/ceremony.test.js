import { expect, test, vi } from 'vitest';
import { ceremonyTimeline, createSpawnGate, runCeremony } from '@battlefield/ceremony.js';

test('the ceremony is slow-mo, a card, then 3-2-1', () => {
  expect(ceremonyTimeline({ reduced: false })).toMatchObject({ slowMs: 550, timeScale: 0.25, countdown: [3, 2, 1] });
});

test('reduced motion skips slow-mo and shake but keeps the card and the count', () => {
  expect(ceremonyTimeline({ reduced: true })).toMatchObject({ slowMs: 0, timeScale: 1, countdown: [3, 2, 1] });
});

test('a boss spawn that arrives during the ceremony waits for the countdown', () => {
  const gate = createSpawnGate();
  const applied = [];
  gate.busy = true;
  gate.hold(() => applied.push('spawn'));
  expect(applied).toEqual([]);
  gate.open();
  expect(applied).toEqual(['spawn']);
  gate.hold(() => applied.push('later'));      // not busy → runs at once
  expect(applied).toEqual(['spawn', 'later']);
});

test('time scales are restored even if a step throws', async () => {
  const scene = { tweens: { timeScale: 1 }, anims: { globalTimeScale: 1 }, cameras: { main: { shake: vi.fn(), flash: vi.fn() } },
    showKillCard: () => { throw new Error('boom'); }, countdown: vi.fn(), wait: () => Promise.resolve() };
  await runCeremony(scene, { killer: { id: 1 }, bossName: 'X', bossNumber: 1 }).catch(() => {});
  expect(scene.cameras.main.shake).toHaveBeenCalled();     // slow-mo really started before the throw
  expect(scene.tweens.timeScale).toBe(1);
  expect(scene.anims.globalTimeScale).toBe(1);
});
