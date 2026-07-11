import { describe, expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({ default: {} }));

import { Charge } from '@battlefield/charge.js';

const FIRE   = [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
const PURPLE = [0x4400aa, 0x6600cc, 0x8833dd, 0xaa55ee, 0xcc88ff];
const GREEN  = [0x005500, 0x117700, 0x33aa00, 0x55cc11, 0x88ee44];
const BLUE   = [0x003366, 0x0055aa, 0x1188cc, 0x44bbee, 0xaaddff];
const ACID   = [0x336600, 0x558800, 0x88bb00, 0xaadd00, 0xddff44];
const GOLD   = [0x886600, 0xaa8800, 0xccaa00, 0xeecc00, 0xffee44];

describe('chargeParticleColors', () => {
  test('returns chargeColors from ftype object', () => {
    const custom = [0x111111, 0x222222, 0x333333, 0x444444, 0x555555];
    expect(Charge.chargeParticleColors({ chargeColors: custom })).toEqual(custom);
  });

  test('returns default fire colors when ftype has no chargeColors', () => {
    const colors = Charge.chargeParticleColors({});
    expect(colors).toEqual(FIRE);
  });

  test('returns default fire colors for null ftype', () => {
    const colors = Charge.chargeParticleColors(null);
    expect(colors).toEqual(FIRE);
  });

  test('returns fire colors for soldier-type fighter (default)', () => {
    expect(Charge.chargeParticleColors({ chargeColors: FIRE })).toEqual(FIRE);
  });

  test('returns purple for werewolf', () => {
    expect(Charge.chargeParticleColors({ chargeColors: PURPLE })).toEqual(PURPLE);
  });

  test('returns green for orc', () => {
    expect(Charge.chargeParticleColors({ chargeColors: GREEN })).toEqual(GREEN);
  });

  test('returns cold blue for skeleton', () => {
    expect(Charge.chargeParticleColors({ chargeColors: BLUE })).toEqual(BLUE);
  });

  test('returns acid green for slime', () => {
    expect(Charge.chargeParticleColors({ chargeColors: ACID })).toEqual(ACID);
  });

  test('returns golden for archer', () => {
    expect(Charge.chargeParticleColors({ chargeColors: GOLD })).toEqual(GOLD);
  });
});
