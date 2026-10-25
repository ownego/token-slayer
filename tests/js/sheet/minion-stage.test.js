// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { MINION_SIZE_RATIO, MINION_TYPES, SPRITE_CHAR_HEIGHT } from '@battlefield/config.js';
import { BOX } from '@battlefield/sheet/sheet-roster.js';
import { BY_KEY } from '@battlefield/sheet/sprite-strip.js';
import { registerCustomRoster } from '@battlefield/sheet/custom-roster.js';
import { FIELD_SCALE, STAGE_BODY_PX, SUMMON_MS, fieldStyles, fighterLook, minionStage } from '@battlefield/sheet/minion-stage.js';

const animations = { idle: { frames: 6, rate: 8 }, walk: { frames: 8, rate: 10 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } };
const demon = { kind: 'default', key: 'demon-a' };
const bowser = { kind: 'default', key: 'smw-bowser' };
const mine = { kind: 'custom', version: 'abc123def456', url_base: '/storage/custom-minions/7/abc123def456', animations, body_px: 22 };
const CUSTOM_FIGHTER = { version: 'fff000fff000', url_base: '/storage/custom-characters/7/fff000fff000', name: 'Sir Pixel', animations };

/**
 * Starts a stage the way Alpine does.
 *
 * @param {object} initial The component's argument.
 * @return {object}
 */
function started(initial) {
  const stage = minionStage(initial);
  stage.init();

  return stage;
}

/**
 * Pretends the viewer has (or has not) asked for less motion.
 *
 * @param {boolean} reduced Whether they have.
 * @return {void}
 */
function reducedMotion(reduced) {
  vi.stubGlobal('matchMedia', () => ({ matches: reduced }));
}

/**
 * Says the pool changed, the way the editor does after an Add or Replace.
 *
 * @param {?string} changed The cell just added or replaced.
 * @return {void}
 */
function poolChanged(changed) {
  window.dispatchEvent(new CustomEvent('custom-minions-changed', { detail: { pool: { rev: 3, defaults: [], custom: [] }, changed } }));
}

const stages = [];
const start = initial => {
  const stage = started(initial);
  stages.push(stage);

  return stage;
};

beforeEach(() => {
  vi.useFakeTimers();
  reducedMotion(false);
});

afterEach(() => {
  stages.splice(0).forEach(stage => stage.destroy());
  registerCustomRoster(BY_KEY, BOX, null);
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('the look on the stage', () => {
  test('is drawn at stage size, from the idle strip of the cell', () => {
    const stage = start({ look: demon });
    const idle = MINION_TYPES.find(type => type.key === 'demon-a').animFiles.idle;

    expect(stage.spriteStyle['--strip']).toBe(`url("${idle.file}")`);
    expect(stage.spriteStyle['--frames']).toBe(idle.count);
    expect(stage.spriteStyle['--rate']).toBe(idle.rate);
    // the body stands STAGE_BODY_PX tall whatever the sheet
    const type = MINION_TYPES.find(candidate => candidate.key === 'demon-a');
    expect((parseFloat(stage.spriteStyle['--size']) / idle.frameWidth) * type.charHeight).toBeCloseTo(STAGE_BODY_PX, 0);
  });

  test('changes its strip when another cell is selected', () => {
    const stage = start({ look: demon });
    const first = stage.spriteStyle['--strip'];

    stage.showLook(bowser);
    expect(stage.spriteStyle['--strip']).not.toBe(first);
    expect(stage.spriteStyle['--strip']).toContain('smw-bowser');

    stage.showLook(mine);
    expect(stage.spriteStyle['--strip']).toBe('url("/storage/custom-minions/7/abc123def456/idle.png")');
  });

  test('draws nothing for a spec it cannot read', () => {
    expect(start({ look: { kind: 'default', key: 'evil' } }).spriteStyle).toEqual({});
  });

  test('marks a minion that has not been added yet as a preview', () => {
    expect(start({ look: mine, preview: true }).preview).toBe(true);
    expect(start({ look: mine }).preview).toBe(false);

    const stage = start({ look: mine, preview: true });
    stage.showLook(demon);
    expect(stage.preview).toBe(false);
  });
});

describe('reduced motion', () => {
  test('holds the stage and the field strip on their first frame', () => {
    reducedMotion(true);
    const stage = start({ look: mine, equipped: 'orc-rider' });

    expect(stage.spriteStyle['--play']).toBe('paused');
    expect(stage.field.minion['--play']).toBe('paused');
    expect(stage.field.fighter['--play']).toBe('paused');
  });

  test('plays them otherwise', () => {
    const stage = start({ look: mine, equipped: 'orc-rider' });

    expect(stage.spriteStyle['--play']).toBe('running');
    expect(stage.field.minion['--play']).toBe('running');
  });

  test('plays no summon pop', () => {
    reducedMotion(true);
    const stage = start({ look: mine, lookKey: 'slot:2', summon: true });

    expect(stage.summoning).toBe(false);
  });
});

describe('the summon after an Add or Replace', () => {
  test('plays once on the cell the pool change names, then stops', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });
    expect(stage.summoning).toBe(false);

    poolChanged('slot:2');
    expect(stage.summoning).toBe(true);

    vi.advanceTimersByTime(SUMMON_MS);
    expect(stage.summoning).toBe(false);

    poolChanged('slot:2');
    expect(stage.summoning).toBe(false);
  });

  test('ignores a change to another cell, and a change that names none (a tick)', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });

    poolChanged('slot:1');
    poolChanged(null);

    expect(stage.summoning).toBe(false);
  });

  test('plays at start when the stage was made by the Add itself, and the event after it adds nothing', () => {
    const stage = start({ look: mine, lookKey: 'slot:2', summon: true });
    expect(stage.summoning).toBe(true);

    vi.advanceTimersByTime(SUMMON_MS);
    poolChanged('slot:2');

    expect(stage.summoning).toBe(false);
  });

  test('stops listening when the stage goes', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });
    stage.destroy();

    poolChanged('slot:2');

    expect(stage.summoning).toBe(false);
  });
});

describe('the field-size strip', () => {
  test('draws the equipped fighter and the minion each in the proportion the field draws them', () => {
    const stage = start({ look: mine, equipped: 'orc-rider' });
    const fighter = fighterLook('orc-rider');
    const { fighter: fighterStyle, minion: minionStyle } = stage.field;

    const fighterBody = (parseFloat(fighterStyle['--size']) / 100) * fighter.bodyPx;
    const minionBody = (parseFloat(minionStyle['--size']) / 100) * 22;

    expect(fighterBody).toBeCloseTo(FIELD_SCALE * SPRITE_CHAR_HEIGHT, 0);
    expect(minionBody).toBeCloseTo(FIELD_SCALE * SPRITE_CHAR_HEIGHT * MINION_SIZE_RATIO, 0);
  });

  test('draws a tall copied-in minion and a short built one at the same height', () => {
    const fighter = fighterLook('orc-rider');
    const built = fieldStyles({ url: '/a.png', frames: 6, rate: 8, frame: 100, bodyPx: 22 }, fighter, false).minion;
    const copied = fieldStyles({ url: '/b.png', frames: 6, rate: 8, frame: 100, bodyPx: 52 }, fighter, false).minion;

    expect((parseFloat(built['--size']) / 100) * 22).toBeCloseTo((parseFloat(copied['--size']) / 100) * 52, 0);
  });

  test('stands the fighter on the floor line by the foot row its body box was measured with', () => {
    const fighter = fighterLook('orc-rider');
    const [, , , footRow] = BOX['orc-rider'];

    expect(fighter.footRatio).toBeCloseTo(footRow / 100, 5);
    const style = fieldStyles(null, fighter, false).fighter;
    expect(parseFloat(style['--below'])).toBeCloseTo(parseFloat(style['--size']) * (1 - footRow / 100), 0);
  });

  test('has no fighter to draw for a key the roster does not know, and shows the minion alone', () => {
    const stage = start({ look: mine, equipped: 'nobody' });

    expect(stage.field.fighter).toBeNull();
    expect(stage.field.minion).not.toBeNull();
  });

  test('puts the custom fighter into the roster when that is the one equipped', () => {
    const stage = start({ look: mine, equipped: 'custom', custom: CUSTOM_FIGHTER });

    expect(BY_KEY.custom).toBeDefined();
    expect(stage.field.fighter['--strip']).toBe('url("/storage/custom-characters/7/fff000fff000/idle.png")');
  });

  test('leaves the roster alone when a roster fighter is equipped', () => {
    registerCustomRoster(BY_KEY, BOX, CUSTOM_FIGHTER);

    start({ look: mine, equipped: 'orc-rider', custom: null });

    expect(BY_KEY.custom).toBeDefined();
  });
});
