// @vitest-environment jsdom
import { describe, expect, test } from 'vitest';
import { chargePalette, faceColor, frameIn, minionMoves, moves, pretty, standOn } from '@battlefield/sheet/sprite-strip.js';

test('a fighter\'s hotbar is idle, walk, its attacks named by type, then death and summon', () => {
  expect(moves('orc-rider').map(m => m.label)).toEqual(['Idle', 'Walk', 'Quick shot', 'Arrow volley', 'Piercing shot', 'Death', 'Summon']);
});

test('summon replays the death strip backwards', () => {
  expect(moves('soldier').find(m => m.id === 'summon')).toMatchObject({ anim: 'death', reverse: true });
});

test('frameIn scales the body box into the target and centres it, capped at 3x', () => {
  const el = document.createElement('span');
  frameIn(el, 'soldier', { maxW: 46, maxH: 36, cyPct: 40 });

  expect(Number(el.style.scale)).toBeCloseTo(2.12, 2);
  expect(el.style.left).toContain('50%');
});

test('pretty turns a key into a display name', () => {
  expect(pretty('orc-rider')).toBe('Orc rider');
});

test('the charge palette comes from the battlefield\'s own fighter config, as CSS hex', () => {
  expect(chargePalette('orc-rider')).toEqual(['#005500', '#117700', '#33aa00', '#55cc11', '#88ee44']);
});

test('a teammate keeps one face colour everywhere', () => {
  expect(faceColor(9)).toBe(faceColor(9));
  expect(faceColor(0)).toBe('#fb923c');
});

test('a hero\'s hotbar names its own moves and marks the big ones', () => {
  const list = moves('batman').map(m => ({ id: m.id, label: m.label, big: Boolean(m.big) }));

  expect(list.filter(m => m.id.startsWith('attack'))).toEqual([
    { id: 'attack1', label: 'Batarang', big: false },
    { id: 'attack2', label: 'Combo', big: false },
    { id: 'attack3', label: 'Explosive Batarang', big: true },
    { id: 'attack4', label: 'Dollar Palm', big: true },
    { id: 'attack5', label: 'Bat Swarm', big: true },
  ]);
});

describe('shrinking a sprite', () => {
  // pixelated shrinking drops whole rows and columns, different ones in every frame, so a tall hero flickers
  test('a sprite drawn smaller than its pixels is smoothed, not pixelated', () => {
    const el = document.createElement('span');
    frameIn(el, 'batman', { maxW: 20, maxH: 20 });

    expect(Number(el.style.scale)).toBeLessThan(1);
    expect(el.style.imageRendering).toBe('auto');
  });

  test('a sprite drawn at or above its pixels keeps the sheet\'s pixelated rendering', () => {
    const el = document.createElement('span');
    el.style.imageRendering = 'auto';
    standOn(el, 'soldier', 3, 26);

    expect(el.style.imageRendering).toBe('');
  });
});

test('a minion\'s hotbar is idle, walk, its attack and the fall it plays when hit', () => {
  expect(minionMoves('minion-draft').map(m => m.label)).toEqual(['Idle', 'Walk', 'Attack', 'Fall']);
});

test('the fall holds on the death strip for about 2s and then stands back up, like the field\'s reaction', () => {
  expect(minionMoves('minion-draft').find(m => m.label === 'Fall')).toMatchObject({ anim: 'death', holdMs: 2000, thenReverse: true });
  expect(minionMoves('minion-draft').find(m => m.label === 'Attack').anim).toBe('attack1');
});

test('moves() gives a minion key its own hotbar, with ids the stage can play', () => {
  expect(moves('minion-peek').map(m => m.id)).toEqual(['idle', 'walk', 'attack1', 'death']);
});
