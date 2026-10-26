// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import { MINION_SIZE_RATIO, MINION_TYPES, SPRITE_CHAR_HEIGHT } from '@battlefield/config.js';
import { BOX } from '@battlefield/sheet/sheet-roster.js';
import { BY_KEY } from '@battlefield/sheet/sprite-strip.js';
import { registerCustomRoster } from '@battlefield/sheet/custom-roster.js';
import { MINION_FALL_HOLD_MS } from '@battlefield/config.js';
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

describe('the hotbar', () => {
  const demonType = MINION_TYPES.find(type => type.key === 'demon-a');
  const ms = (frames, rate) => Math.round(frames / rate * 1000);
  const demonFall = ms(demonType.animFiles.death.count, demonType.animFiles.death.rate);

  test('lists Idle, Walk, Attack and Fall for a default look, a custom look and a copied-in one alike', () => {
    for (const look of [demon, bowser, mine]) {
      expect(start({ look }).moves).toEqual([
        { id: 'idle', label: 'Idle' },
        { id: 'walk', label: 'Walk' },
        { id: 'attack', label: 'Attack' },
        { id: 'fall', label: 'Fall' },
      ]);
    }
  });

  test('lists only the moves the look has a strip for', () => {
    const bare = { ...mine, animations: { idle: animations.idle, walk: animations.walk } };

    expect(start({ look: bare }).moves.map(move => move.id)).toEqual(['idle', 'walk']);
  });

  test('lists nothing for a spec it cannot read', () => {
    expect(start({ look: { kind: 'default', key: 'evil' } }).moves).toEqual([]);
  });

  test('stands idle to begin with, looping the idle strip', () => {
    const stage = start({ look: demon });

    expect(stage.move).toBe('idle');
    expect(stage.spriteStyle['--strip']).toBe(`url("${demonType.animFiles.idle.file}")`);
    expect(stage.spriteStyle['--ms-iter']).toBe('infinite');
  });

  test('Walk loops the walk strip at the same body height', () => {
    const stage = start({ look: demon });

    stage.play('walk');

    expect(stage.move).toBe('walk');
    expect(stage.spriteStyle['--strip']).toBe(`url("${demonType.animFiles.walk.file}")`);
    expect(stage.spriteStyle['--frames']).toBe(demonType.animFiles.walk.count);
    expect(stage.spriteStyle['--ms-iter']).toBe('infinite');
    expect((parseFloat(stage.spriteStyle['--size']) / 100) * demonType.charHeight).toBeCloseTo(STAGE_BODY_PX, 0);
  });

  test('a custom look plays the strips of its animation table', () => {
    const stage = start({ look: mine });

    stage.play('attack');
    expect(stage.spriteStyle['--strip']).toBe('url("/storage/custom-minions/7/abc123def456/attack.png")');
    expect(stage.spriteStyle['--rate']).toBe(12);
  });

  test('Attack plays once, then goes back to Idle when the strip is done', () => {
    const stage = start({ look: demon });
    const attack = demonType.animFiles[demonType.attacks[0].anim];

    stage.play('attack');
    expect(stage.spriteStyle['--strip']).toBe(`url("${attack.file}")`);
    expect(stage.spriteStyle['--ms-iter']).toBe('1');

    vi.advanceTimersByTime(ms(attack.count, attack.rate) - 1);
    expect(stage.move).toBe('attack');
    vi.advanceTimersByTime(1);
    expect(stage.move).toBe('idle');
    expect(stage.spriteStyle['--strip']).toBe(`url("${demonType.animFiles.idle.file}")`);
    expect(stage.spriteStyle['--ms-iter']).toBe('infinite');
  });

  test('Fall lies on the death strip, holds its last frame for MINION_FALL_HOLD_MS, gets up backwards, then goes back to Idle', () => {
    const stage = start({ look: demon });
    const death = `url("${demonType.animFiles.death.file}")`;

    stage.play('fall');
    expect(stage.spriteStyle['--strip']).toBe(death);
    expect(stage.spriteStyle['--ms-anim']).toBe('fs-ms-once');

    vi.advanceTimersByTime(demonFall + MINION_FALL_HOLD_MS - 1);
    expect(stage.spriteStyle['--ms-anim']).toBe('fs-ms-once');
    vi.advanceTimersByTime(1);
    expect(stage.move).toBe('fall');
    expect(stage.spriteStyle['--strip']).toBe(death);
    expect(stage.spriteStyle['--ms-anim']).toBe('fs-ms-back-b');

    vi.advanceTimersByTime(demonFall);
    expect(stage.move).toBe('idle');
    expect(stage.spriteStyle['--ms-iter']).toBe('infinite');
  });

  test('a move played while a one-shot is running starts its own animation again, not the one already running', () => {
    const stage = start({ look: demon });

    stage.play('attack');
    const first = stage.spriteStyle['--ms-anim'];
    stage.play('fall');
    const second = stage.spriteStyle['--ms-anim'];
    stage.play('attack');
    const third = stage.spriteStyle['--ms-anim'];

    expect([first, second, third]).toEqual(['fs-ms-once', 'fs-ms-once-b', 'fs-ms-once']);
  });

  test('a custom look falls the same way, for the same hold', () => {
    const stage = start({ look: mine });

    stage.play('fall');
    vi.advanceTimersByTime(ms(3, 6) + MINION_FALL_HOLD_MS);
    expect(stage.spriteStyle['--ms-anim']).toBe('fs-ms-back-b');
    vi.advanceTimersByTime(ms(3, 6));
    expect(stage.move).toBe('idle');
  });

  test('Idle brings a move back to a loop at once, and another move cuts the one playing short', () => {
    const stage = start({ look: demon });

    stage.play('fall');
    vi.advanceTimersByTime(100);
    stage.play('walk');
    expect(stage.move).toBe('walk');
    expect(stage.spriteStyle['--ms-iter']).toBe('infinite');

    // the fall's timer is gone: it does not push the stage to its next beat or to Idle later
    vi.advanceTimersByTime(demonFall + MINION_FALL_HOLD_MS + demonFall);
    expect(stage.move).toBe('walk');

    stage.play('attack');
    stage.play('idle');
    vi.advanceTimersByTime(5000);
    expect(stage.move).toBe('idle');
    expect(vi.getTimerCount()).toBe(0);
  });

  test('pressing the move already playing does not restart it, but a finished one can be played again', () => {
    const stage = start({ look: demon });
    const attack = demonType.animFiles[demonType.attacks[0].anim];

    stage.play('attack');
    vi.advanceTimersByTime(100);
    stage.play('attack');
    vi.advanceTimersByTime(ms(attack.count, attack.rate) - 100);
    expect(stage.move).toBe('idle');

    stage.play('attack');
    expect(stage.move).toBe('attack');
  });

  test('ignores a move the look does not have', () => {
    const stage = start({ look: { ...mine, animations: { idle: animations.idle } } });

    stage.play('fall');

    expect(stage.move).toBe('idle');
  });

  test('showing another look stands it idle and drops the move being played', () => {
    const stage = start({ look: demon });

    stage.play('fall');
    stage.showLook(bowser);

    expect(stage.move).toBe('idle');
    expect(stage.spriteStyle['--strip']).toContain('smw-bowser/idle');
    expect(vi.getTimerCount()).toBe(0);
    expect(stage.moves.map(move => move.id)).toEqual(['idle', 'walk', 'attack', 'fall']);
  });

  test('cancels the move being played when the stage goes', () => {
    const stage = start({ look: demon });

    stage.play('fall');
    stage.destroy();

    expect(vi.getTimerCount()).toBe(0);
  });

  test('under reduced motion plays nothing: the button changes the move and the sprite stands on its first frame', () => {
    reducedMotion(true);
    const stage = start({ look: demon });

    stage.play('fall');

    expect(stage.move).toBe('fall');
    expect(stage.spriteStyle['--strip']).toBe(`url("${demonType.animFiles.death.file}")`);
    expect(stage.spriteStyle['--play']).toBe('paused');
    expect(vi.getTimerCount()).toBe(0);

    vi.advanceTimersByTime(10000);
    expect(stage.move).toBe('fall');
  });

  test('leaves the At field size strip idling whatever the stage plays', () => {
    const stage = start({ look: demon, equipped: 'orc-rider' });
    const before = stage.field.minion['--strip'];

    stage.play('fall');

    expect(stage.field.minion['--strip']).toBe(before);
    expect(before).toBe(`url("${demonType.animFiles.idle.file}")`);
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
  test('plays on the cell the pool change names, every time it is added again', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });
    expect(stage.summoning).toBe(false);

    poolChanged('slot:2');
    expect(stage.summoning || stage.phase === 'back').toBe(true);

    vi.advanceTimersByTime(5000);
    expect(stage.summoning).toBe(false);
    expect(stage.phase).toBe('loop');

    poolChanged('slot:2');
    expect(stage.summoning || stage.phase === 'back').toBe(true);
  });

  test('ignores a change to another cell, and a change that names none', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });

    poolChanged('slot:1');
    poolChanged(null);

    expect(stage.summoning).toBe(false);
    expect(stage.phase).toBe('loop');
  });

  test('plays at start when the stage was made by the Add itself', () => {
    const stage = start({ look: bowser, lookKey: 'default:smw-bowser', summon: true });

    expect(stage.summoning).toBe(true);
    vi.advanceTimersByTime(SUMMON_MS);
    expect(stage.summoning).toBe(false);
  });

  test('stops listening when the stage goes', () => {
    const stage = start({ look: mine, lookKey: 'slot:2' });
    stage.destroy();

    poolChanged('slot:2');

    expect(stage.summoning).toBe(false);
    expect(stage.phase).toBe('loop');
  });
});

describe('one stage that follows the pick', () => {
  const board = {
    saved: {},
    looks: {
      'default:demon-a': { spec: demon, version: 'demon-a', preview: false, name: 'Demon', slot: null, hash: null },
      'default:smw-bowser': { spec: bowser, version: 'smw-bowser', preview: false, name: 'Bowser', slot: null, hash: null },
      'slot:2': { spec: mine, version: 'abc123def456', preview: false, name: 'Minion 2', slot: 2, hash: 'abc123def456' },
      'slot:3': { spec: null, version: null, preview: false, name: null, slot: 3, hash: null },
    },
  };

  /**
   * A stage inside the pool's scope, the way Alpine nests it: `sel`, `entered` and `board` come from the pool.
   *
   * @return {{stage: object, watch: Object<string, Function>, boardChanged: Function}}
   */
  function nested() {
    const watch = {};
    let onBoard = () => {};
    const stage = minionStage({ look: demon, lookKey: 'default:demon-a' });
    Object.assign(stage, {
      sel: 'default:demon-a',
      entered: 0,
      board: structuredClone(board),
      $watch: (name, fn) => { watch[name] = fn; },
      $wire: { $watch: (name, fn) => { if (name === 'board') onBoard = fn; } },
    });
    stage.init();
    stages.push(stage);

    return { stage, watch, boardChanged: () => onBoard() };
  }

  test('a pick swaps the look in place and summons it', () => {
    const { stage, watch } = nested();

    stage.sel = 'default:smw-bowser';
    watch.sel('default:smw-bowser');

    expect(stage.look.url).toBe(MINION_TYPES.find(type => type.key === 'smw-bowser').animFiles.idle.file);
    expect(stage.lookName).toBe('Bowser');
    expect(stage.summoning).toBe(true);
  });

  test('a look with a death strip rises by playing it backwards, then stands idle', () => {
    const { stage, watch } = nested();

    stage.sel = 'slot:2';
    watch.sel('slot:2');

    expect(stage.phase).toBe('back');
    expect(stage.strip).toBe('fall');
    vi.advanceTimersByTime(5000);
    expect(stage.phase).toBe('loop');
    expect(stage.strip).toBe('idle');
  });

  test('two quick picks land once, on the second look', () => {
    const { stage, watch } = nested();

    stage.sel = 'default:smw-bowser';
    watch.sel('default:smw-bowser');
    vi.advanceTimersByTime(100);
    stage.sel = 'slot:2';
    watch.sel('slot:2');
    vi.advanceTimersByTime(5000);

    expect(stage.landed).toBe(1);
    expect(stage.lookName).toBe('Minion 2');
  });

  test('a pick of an empty slot keeps the stage as it was and summons nothing', () => {
    const { stage, watch } = nested();

    stage.sel = 'slot:3';
    watch.sel('slot:3');

    expect(stage.lookName).toBe('Demon');
    expect(stage.summoning).toBe(false);
  });

  test('a minion replaced in place is drawn again when the board brings its new version', () => {
    const { stage, watch, boardChanged } = nested();
    stage.sel = 'slot:2';
    watch.sel('slot:2');
    vi.advanceTimersByTime(5000);

    stage.board.looks['slot:2'] = { ...stage.board.looks['slot:2'], version: 'fff000fff000', spec: { ...mine, version: 'fff000fff000', url_base: '/storage/custom-minions/7/fff000fff000' } };
    boardChanged();

    expect(stage.look.url).toContain('fff000fff000');
  });

  test('coming into view summons the look on the stage again', () => {
    const { stage, watch } = nested();

    watch.entered(1);

    expect(stage.summoning || stage.phase === 'back').toBe(true);
  });

  test('summons nothing under reduced motion, but still swaps the look', () => {
    reducedMotion(true);
    const { stage, watch } = nested();

    stage.sel = 'slot:2';
    watch.sel('slot:2');

    expect(stage.lookName).toBe('Minion 2');
    expect(stage.summoning).toBe(false);
    expect(stage.phase).toBe('loop');
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
