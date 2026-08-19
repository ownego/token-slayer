import { describe, expect, test, vi } from 'vitest';
import { createSkillLoop } from '@battlefield/character-preview/skill-loop.js';

function makeHarness() {
  const playCalls = [];
  const scheduled = [];
  let pendingComplete = null;

  const playAnimation = vi.fn((skill, onComplete) => {
    playCalls.push(skill.id);
    pendingComplete = onComplete;
  });
  const scheduleReplay = vi.fn((fn, delayMs) => {
    scheduled.push({ fn, delayMs });
  });

  return {
    loop: createSkillLoop({ playAnimation, scheduleReplay, holdMs: 1000 }),
    playCalls,
    scheduled,
    fireComplete: () => pendingComplete?.(),
  };
}

describe('createSkillLoop', () => {
  test('selecting a looping skill plays it once and never schedules a replay', () => {
    const { loop, playCalls, scheduled, fireComplete } = makeHarness();

    loop.select({ id: 'idle', loop: true });
    fireComplete();

    expect(playCalls).toEqual(['idle']);
    expect(scheduled).toHaveLength(0);
  });

  test('selecting a one-shot skill schedules a replay after it completes', () => {
    const { loop, playCalls, scheduled, fireComplete } = makeHarness();

    loop.select({ id: 'attack1', loop: false });
    fireComplete();

    expect(playCalls).toEqual(['attack1']);
    expect(scheduled).toHaveLength(1);
    expect(scheduled[0].delayMs).toBe(1000);

    scheduled[0].fn();
    expect(playCalls).toEqual(['attack1', 'attack1']);
  });

  test('selecting a new skill mid-flight cancels the stale completion', () => {
    const { loop, playCalls, scheduled, fireComplete } = makeHarness();

    loop.select({ id: 'attack1', loop: false });
    loop.select({ id: 'idle', loop: true });
    fireComplete(); // the attack1 completion callback still fires late

    expect(playCalls).toEqual(['attack1', 'idle']);
    expect(scheduled).toHaveLength(0);
  });

  test('cancel() stops a scheduled replay from doing anything when it fires', () => {
    const { loop, playCalls, scheduled, fireComplete } = makeHarness();

    loop.select({ id: 'death', loop: false });
    fireComplete();
    loop.cancel();
    scheduled[0].fn();

    expect(playCalls).toEqual(['death']);
  });
});
