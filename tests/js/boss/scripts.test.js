import { describe, expect, test } from 'vitest';
import { scriptFor, scriptToRun } from '@battlefield/boss/scripts/index.js';
import { BOSS_TYPES } from '@battlefield/config.js';

describe('boss script registry', () => {
  test('resolves the thanos script by its BOSS_TYPES key', () => {
    const script = scriptFor('boss-thanos');
    expect(script).toBeTruthy();
    expect(typeof script.create).toBe('function');
    expect(typeof script.destroy).toBe('function');
  });

  test('returns null for a boss with no script, so the engine skips every hook', () => {
    expect(scriptFor('boss-ghost')).toBeNull();
    expect(scriptFor(undefined)).toBeNull();
  });

  test('the thanos script translates the flat broadcast payload into its own state', () => {
    // The engine hands the raw snake_case BossSpawned payload to readState and
    // stores the result at bossState.script — it never knows these key names.
    const script = scriptFor('boss-thanos');

    expect(script.readState({ stones: 2, stone_schedule: 'a,b' })).toEqual({ stones: 2, stoneSchedule: 'a,b' });
  });

  test('the thanos script reads a payload without stone keys as an empty stone state', () => {
    const script = scriptFor('boss-thanos');

    expect(script.readState({ boss_number: 7, max_hp: 1000 })).toEqual({ stones: 0, stoneSchedule: '' });
  });

  test('every registered key is a real BOSS_TYPES key', () => {
    // A typo here would silently register a script nobody ever runs.
    const keys = new Set(BOSS_TYPES.map((b) => b.key));
    for (const key of Object.keys(scriptFor.registry)) {
      expect(keys.has(key), `unknown boss key in registry: ${key}`).toBe(true);
    }
  });
});

describe('scriptToRun', () => {
  test('runs the thanos script for a live ThaNode that carries its state', () => {
    expect(scriptToRun('boss-thanos', { script: { stones: 1 } })).toBe(scriptFor('boss-thanos'));
  });

  test('does not run it for a killed ThaNode, whose state the kill cleared', () => {
    // A rotate during the kill ceremony reboots the scene from a snapshot of
    // the dead boss; its sockets and ticker must not come back.
    expect(scriptToRun('boss-thanos', { name: 'ThaNode' })).toBeNull();
  });

  test('is null for a boss with no script', () => {
    expect(scriptToRun('boss-ghost', {})).toBeNull();
  });
});

