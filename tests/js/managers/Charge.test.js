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

describe('sparkTints', () => {
  test("spark tints come from the fighter's own charge palette plus white-hot, never hard-coded green", () => {
    const tints = Charge.sparkTints([0x111111, 0x222222, 0x333333, 0x444444, 0x555555]);
    expect(tints).toContain(0xffffff);
    expect(tints).toContain(0x555555);
    expect(tints).not.toContain(0x4ade80);
  });
});

describe('particle emitters removed', () => {
  test('a charging fighter no longer owns its own particle emitters', () => {
    expect(Charge.prototype.spawnChargeFireEmitters).toBeUndefined();
    expect(Charge.prototype.createChargingTrail).toBeUndefined();
  });
});

describe('handleCharging for a fighter this client does not have', () => {
  test('rejoins it with its custom character, not just its roster one', () => {
    const joined = vi.fn();
    const scene = { fighters: new Map(), charges: new Map(), fighter: { handleFighterJoined: joined } };
    const custom = { version: 'abc', url_base: '/storage/custom-characters/7/abc', animations: {} };

    new Charge(scene).handleCharging({ user_id: 7, character: 'soldier', custom_character: custom, position: null });

    expect(joined).toHaveBeenCalledWith(expect.objectContaining({ user_id: 7, character: 'soldier', custom_character: custom }));
  });

  test('the synthesised join carries custom_minions', () => {
    const joined = vi.fn();
    const scene = { fighters: new Map(), charges: new Map(), fighter: { handleFighterJoined: joined } };
    const minions = [{ version: 'abc', url_base: '/storage/custom-minions/7/abc', body_px: 22, animations: {} }];

    new Charge(scene).handleCharging({ user_id: 7, character: 'soldier', custom_minions: minions });

    expect(joined).toHaveBeenCalledWith(expect.objectContaining({ user_id: 7, custom_minions: minions }));
  });

  test('a join that already carried custom_minions does not forward them again', () => {
    const handleMinionsChanged = vi.fn();
    const fighters = new Map();
    const scene = {
      fighters,
      charges: new Map([[7, { activity: '' }]]),
      minions: { handleMinionsChanged },
      fighter: { handleFighterJoined: ({ user_id: id }) => fighters.set(id, { id, ftype: { key: 'soldier' }, sprite: {}, body: { play() {} } }), updateCharacters: vi.fn() },
    };

    new Charge(scene).handleCharging({ user_id: 7, character: 'soldier', custom_minions: [] });

    expect(handleMinionsChanged).not.toHaveBeenCalled();
  });
});

describe('handleCharging for a fighter already on the field', () => {
  const custom = { version: 'abc', url_base: '/storage/custom-characters/7/abc', animations: {} };
  const sceneWith = ftype => ({
    fighters: new Map([[7, { id: 7, ftype, sprite: {}, body: { play() {} } }]]),
    charges: new Map([[7, { activity: '' }]]),
    fighter: { updateCharacters: vi.fn() },
  });

  test('reconciles a custom fighter this client missed the change of (Reverb has no replay)', () => {
    const scene = sceneWith({ key: 'soldier' });

    new Charge(scene).handleCharging({ user_id: 7, character: 'soldier', custom_character: custom });

    expect(scene.fighter.updateCharacters).toHaveBeenCalledWith([{ user_id: 7, character: 'soldier', custom_character: custom }]);
  });

  test('reconciles a custom fighter whose removal this client missed', () => {
    const scene = sceneWith({ key: 'custom-7-abc', custom: true });

    new Charge(scene).handleCharging({ user_id: 7, character: 'wizard', custom_character: null });

    expect(scene.fighter.updateCharacters).toHaveBeenCalledWith([{ user_id: 7, character: 'wizard', custom_character: null }]);
  });

  test('leaves a roster fighter alone, as before', () => {
    const scene = sceneWith({ key: 'soldier' });

    new Charge(scene).handleCharging({ user_id: 7, character: 'wizard', custom_character: null });

    expect(scene.fighter.updateCharacters).not.toHaveBeenCalled();
  });
});

describe('handleCharging and a fighter\'s custom minions', () => {
  const minions = [{ version: 'abc', url_base: '/storage/custom-minions/7/abc', body_px: 22, animations: {} }];
  const sceneWith = entry => ({
    fighters: new Map([[7, { id: 7, ftype: { key: 'soldier' }, sprite: {}, body: { play() {} }, ...entry }]]),
    charges: new Map([[7, { activity: '' }]]),
    fighter: { updateCharacters: vi.fn() },
    minions: { handleMinionsChanged: vi.fn() },
  });

  test('forwards an array custom_minions for a fighter on the field, raw', () => {
    const scene = sceneWith({});

    new Charge(scene).handleCharging({ user_id: 7, custom_minions: minions });

    expect(scene.minions.handleMinionsChanged).toHaveBeenCalledWith({ user_id: 7, custom_minions: minions });
  });

  test('forwards an empty set too: [] means the player removed them all', () => {
    const scene = sceneWith({});

    new Charge(scene).handleCharging({ user_id: 7, custom_minions: [] });

    expect(scene.minions.handleMinionsChanged).toHaveBeenCalledWith({ user_id: 7, custom_minions: [] });
  });

  test.each([
    ['missing (scene.js seeds a charge without it)', {}],
    ['null', { custom_minions: null }],
    ['not an array', { custom_minions: 'abc' }],
  ])('does not forward when custom_minions is %s', (_, extra) => {
    const scene = sceneWith({});

    new Charge(scene).handleCharging({ user_id: 7, activity: 'x', ...extra });

    expect(scene.minions.handleMinionsChanged).not.toHaveBeenCalled();
  });

  test('never writes entry.customMinions itself', () => {
    const own = [{ version: 'old' }];
    const scene = sceneWith({ customMinions: own });

    new Charge(scene).handleCharging({ user_id: 7, custom_minions: minions });

    expect(scene.fighters.get(7).customMinions).toBe(own);
  });

  test('a scene whose minions manager is not wired yet is a no-op, not a crash', () => {
    const scene = sceneWith({});
    delete scene.minions;

    expect(() => new Charge(scene).handleCharging({ user_id: 7, custom_minions: minions })).not.toThrow();
  });
});
