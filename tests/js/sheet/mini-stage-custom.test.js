// @vitest-environment jsdom
import { afterEach, describe, expect, test, vi } from 'vitest';
import { miniStage } from '@battlefield/sheet/mini-stage.js';
import { BY_KEY, nameOf } from '@battlefield/sheet/sprite-strip.js';
import { BOX } from '@battlefield/sheet/sheet-roster.js';
import { registerCustomRoster } from '@battlefield/sheet/custom-roster.js';

const CUSTOM = {
  version: 'abc123def456',
  url_base: '/storage/custom-characters/7/abc123def456',
  name: 'Sir Pixel',
  active: true,
  animations: {
    idle: { frames: 6, rate: 8 },
    walk: { frames: 8, rate: 10 },
    attack: { frames: 6, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
};

afterEach(() => {
  registerCustomRoster(BY_KEY, BOX, null);
  vi.unstubAllGlobals();
});

describe('nameOf', () => {
  test('is the roster name for a roster fighter', () => {
    expect(nameOf('orc-rider')).toBe('Orc rider');
  });

  test('is the name the player chose for the custom fighter, and Custom before it is known', () => {
    expect(nameOf('custom')).toBe('Custom');

    registerCustomRoster(BY_KEY, BOX, CUSTOM);

    expect(nameOf('custom')).toBe('Sir Pixel');
  });
});

describe('miniStage with a custom fighter', () => {
  test('opens on the custom fighter when it is the equipped one, even though the Character tab has not mounted yet', () => {
    const stage = miniStage('custom', 0, 7, CUSTOM);

    expect(stage.key).toBe('custom');
    expect(stage.name).toBe('Sir Pixel');
  });

  test('without a custom payload an equipped key it does not know falls back to the first roster fighter', () => {
    expect(miniStage('custom', 0, 7).key).toBe(Object.keys(BY_KEY)[0]);
  });

  test('a roster fighter is unaffected', () => {
    const stage = miniStage('knight', 0, 7, null);

    expect(stage.key).toBe('knight');
    expect(stage.name).toBe('Knight');
  });

  test('the editor making or replacing the fighter moves the stage onto it', () => {
    const stage = miniStage('knight', 0, 7);

    stage.onCustomChanged({ custom: CUSTOM, equipped: 'custom' });

    expect(stage.key).toBe('custom');
    expect(stage.name).toBe('Sir Pixel');
  });

  test('removing it puts the stage back on the roster character the player falls back to', () => {
    const stage = miniStage('custom', 0, 7, CUSTOM);

    stage.onCustomChanged({ custom: null, equipped: 'wizard' });

    expect(stage.key).toBe('wizard');
  });

  test('a fighter that is not the equipped one leaves the stage on the roster character', () => {
    const stage = miniStage('knight', 0, 7);

    stage.onCustomChanged({ custom: { ...CUSTOM, active: false }, equipped: 'knight' });

    expect(stage.key).toBe('knight');
  });

  test('a live broadcast that carries the custom fighter keeps the stage on it', () => {
    const stage = miniStage('knight', 0, 7, CUSTOM);

    expect(stage.keyFor({ user_id: 7, character: 'knight', custom_character: { version: 'abc' } })).toBe('custom');
    expect(stage.keyFor({ user_id: 7, character: 'wizard', custom_character: null })).toBe('wizard');
    expect(stage.keyFor({ user_id: 7, character: 'not-a-fighter', custom_character: null })).toBeNull();
  });

  test('the existing battlefield bus handler forwards this player\'s change to the character stage', () => {
    const stage = miniStage('knight', 0, 7);
    stage.paintFighter = vi.fn();
    const events = [];
    window.addEventListener('sheet-own-character-broadcast', event => events.push(event.detail));

    stage.onCharacterBroadcast({ user_id: 8, character: 'wizard', custom_character: null });
    stage.onCharacterBroadcast({ user_id: 7, character: 'knight', custom_character: null });

    expect(events).toEqual([{ user_id: 7 }]);
  });

  test('a teammate changing their fighter asks the open character stage to refresh its previews', () => {
    const stage = miniStage('knight', 0, 7);
    const changed = vi.fn();
    window.addEventListener('sheet-other-character-broadcast', changed, { once: true });

    stage.onCharacterBroadcast({ user_id: 8, character: 'wizard', custom_character: null });

    expect(changed).toHaveBeenCalledOnce();
  });
});

describe('miniStage painting the equipped fighter', () => {
  /**
   * Paints a stage for `key` into a detached profile tab and returns the fighter's strip.
   *
   * @param {string} key
   * @return {string} The strip the fighter element shows.
   */
  function stripOf(key) {
    const tab = document.createElement('div');
    tab.id = 'tab-profile';
    const fighter = document.createElement('span');
    tab.appendChild(fighter);
    const stage = miniStage(key, 0, 7);
    Object.assign(stage, { $refs: { fighter }, $el: fighter });
    stage.paintFighter();
    return fighter.style.backgroundImage;
  }

  test.each(['iron-man', 'superman'])('%s hovers on its idle, as fliers do on the field while their player works', key => {
    expect(stripOf(key)).toContain(`${key}-idle`);
  });

  test('a fighter on foot runs', () => {
    expect(stripOf('captain-america')).toContain('captain-america-walk');
  });
});

describe('miniStage sizing the equipped fighter', () => {
  /**
   * The body height, in px, the stage draws `key` at.
   *
   * @param {string} key
   * @return {number}
   */
  function bodyPx(key) {
    const fighter = document.createElement('span');
    const stage = miniStage(key, 0, 7);
    Object.assign(stage, { $refs: { fighter }, $el: fighter });
    stage.paintFighter();
    const [, y0, , y1] = BOX[key];
    return Number(fighter.style.scale) * (y1 - y0);
  }

  test.each(['captain-america', 'batman'])('draws the tall %s at the stage\'s body height, not three times its own', key => {
    expect(bodyPx(key)).toBeCloseTo(84, 0);
  });

  test('keeps a roster fighter on its whole-pixel scale', () => {
    const [, y0, , y1] = BOX.soldier;
    expect(bodyPx('soldier')).toBe(5 * (y1 - y0));
  });
});

describe('miniStage with a minion pool', () => {
  const minion = (version, frames = 6, bodyPx = 22) => ({
    id: 1,
    version,
    url_base: `/storage/custom-minions/7/${version}`,
    body_px: bodyPx,
    source: 'upload',
    animations: { idle: { frames, rate: 8 }, walk: { frames: 8, rate: 16 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } },
  });
  const poolOf = (rev, custom = [], defaults = ['demon-a']) => ({ rev, defaults, custom });
  const mount = (pool, total = 2) => miniStage('knight', total, 7, null, pool);

  test('draws the pool\'s looks, the player\'s own first and then the defaults still ticked', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa')]));

    expect(stage.minionSpots).toHaveLength(2);
    expect(stage.minionSpots[0].look).toMatchObject({ url: '/storage/custom-minions/7/aaaaaaaaaaaa/idle.png', frames: 6, rate: 8 });
    expect(stage.minionSpots[1].look.url).toContain('demon-a');
  });

  test('a player who has not opened the pool still sees the five defaults, and no pool at all is the same', () => {
    const everyDefault = mount(poolOf(0, [], ['demon-a', 'blood-monster-a', 'smw-goomba', 'smw-babybowser', 'smw-bowser']), 6);

    expect(everyDefault.minionSpots.map(spot => spot.look.url)).toHaveLength(6);
    expect(new Set(everyDefault.minionSpots.slice(0, 5).map(spot => spot.look.url)).size).toBe(5);
    expect(mount(null).looks.length).toBeGreaterThan(1);
  });

  test('with no subagent working no minion stands beside the fighter, however many looks the pool holds', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa')]), 0);

    expect(stage.looks).toHaveLength(2);
    expect(stage.minionSpots).toEqual([]);
  });

  test('a pool change with no subagent working draws nothing new, and the next subagent takes its looks', () => {
    const stage = mount(poolOf(1), 0);

    expect(stage.onPoolChanged(poolOf(2, [minion('aaaaaaaaaaaa')]))).toBe(true);
    expect(stage.minionSpots).toEqual([]);

    stage.crew = { total: 1, busy: new Set() };
    expect(stage.minionSpots[0].look.url).toBe('/storage/custom-minions/7/aaaaaaaaaaaa/idle.png');
  });

  test('a working minion says it is working, an idle crew member idle', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa')]));
    stage.crew = { total: 2, busy: new Set(['agent-1']) };

    expect(stage.minionSpots.map(spot => stage.spotTitle(spot))).toEqual(['working', 'idle']);
  });

  test('follows the editor: a newer pool redraws the swarm at once', () => {
    const stage = mount(poolOf(1));

    expect(stage.onPoolChanged(poolOf(2, [minion('aaaaaaaaaaaa'), minion('bbbbbbbbbbbb', 4)]))).toBe(true);

    expect(stage.minionSpots.map(spot => spot.look.frames)).toEqual([6, 4]);
    expect(stage.pool.rev).toBe(2);
  });

  test('a pool no newer than the one held is ignored, so a late or repeated report cannot bring a removed minion back', () => {
    const stage = mount(poolOf(5, [minion('aaaaaaaaaaaa')]));

    expect(stage.onPoolChanged(poolOf(4, [minion('aaaaaaaaaaaa'), minion('bbbbbbbbbbbb')]))).toBe(false);
    expect(stage.onPoolChanged(poolOf(5, []))).toBe(false);

    expect(stage.pool.rev).toBe(5);
    expect(stage.looks.map(entry => entry.url)).toContain('/storage/custom-minions/7/aaaaaaaaaaaa/idle.png');
  });

  test('something that is not a pool leaves the stage alone', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa')]));

    expect(stage.onPoolChanged(undefined)).toBe(false);
    expect(stage.onPoolChanged({ custom: [] })).toBe(false);
    expect(stage.onPoolChanged('pool')).toBe(false);

    expect(stage.pool.rev).toBe(1);
    expect(stage.looks).toHaveLength(2);
  });

  test('takes the pool the editor reports as a window event, and stops when the stage is gone', () => {
    vi.stubGlobal('requestAnimationFrame', vi.fn(() => 0));
    const tab = document.createElement('div');
    tab.id = 'tab-profile';
    const parts = ['stage', 'fighter', 'crew'].map(() => tab.appendChild(document.createElement('span')));
    const stage = mount(poolOf(1));
    Object.assign(stage, { $refs: { stage: parts[0], fighter: parts[1], crew: parts[2] }, $el: tab });
    stage.init();

    window.dispatchEvent(new CustomEvent('custom-minions-changed', { detail: { pool: poolOf(2, [minion('aaaaaaaaaaaa')]) } }));
    expect(stage.pool.rev).toBe(2);

    stage.destroy();
    window.dispatchEvent(new CustomEvent('custom-minions-changed', { detail: { pool: poolOf(3, []) } }));
    expect(stage.pool.rev).toBe(2);
  });

  test('a broadcast of this player\'s newer pool redraws it and asks the Minions editor to re-read; another player\'s does neither', () => {
    const refresh = vi.fn();
    vi.stubGlobal('Livewire', { dispatch: refresh });
    const stage = mount(poolOf(1));

    stage.onMinionsBroadcast({ user_id: 8, minion_pool: poolOf(2, [minion('aaaaaaaaaaaa')]) });
    expect(stage.pool.rev).toBe(1);
    expect(refresh).not.toHaveBeenCalled();

    stage.onMinionsBroadcast({ user_id: 7, minion_pool: poolOf(2, [minion('aaaaaaaaaaaa')]) });
    expect(stage.pool.rev).toBe(2);
    expect(refresh.mock.calls).toEqual([['custom-minions-refresh']]);
  });

  test('the echo of a change the editor already reported asks for nothing: the pool it carries is not newer', () => {
    const refresh = vi.fn();
    vi.stubGlobal('Livewire', { dispatch: refresh });
    const stage = mount(poolOf(1));
    stage.onPoolChanged(poolOf(2, [minion('aaaaaaaaaaaa')]));

    stage.onMinionsBroadcast({ user_id: 7, minion_pool: poolOf(2, [minion('aaaaaaaaaaaa')]) });

    expect(refresh).not.toHaveBeenCalled();
  });

  test('a broadcast with no pool in it, or an older one, changes nothing and asks for nothing', () => {
    const refresh = vi.fn();
    vi.stubGlobal('Livewire', { dispatch: refresh });
    const stage = mount(poolOf(3, [minion('aaaaaaaaaaaa')]));

    stage.onMinionsBroadcast({ user_id: 7 });
    stage.onMinionsBroadcast({ user_id: 7, minion_pool: poolOf(2, []) });

    expect(stage.pool.rev).toBe(3);
    expect(refresh).not.toHaveBeenCalled();
  });

  test('styles a minion by its strip, frame count, rate and size, as properties not as text', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa')]), 1);
    const style = stage.spotStyle({ x: -72, y: 0, look: stage.looks[0] });

    expect(style).toMatchObject({ '--mx': '-72px', '--my': '0px', '--frames': 6, '--rate': 8 });
    expect(style['--strip']).toBe('url("/storage/custom-minions/7/aaaaaaaaaaaa/idle.png")');
  });

  test('draws every look at the same body height, whatever its frame or measured body', () => {
    const stage = mount(poolOf(1, [minion('aaaaaaaaaaaa', 6, 22), minion('bbbbbbbbbbbb', 6, 44)], ['smw-bowser']), 3);
    const bodyOf = spot => parseFloat(stage.spotStyle(spot)['--size']) * spot.look.bodyPx / spot.look.frame;

    const heights = stage.minionSpots.map(bodyOf);

    expect(Math.max(...heights) - Math.min(...heights)).toBeLessThan(0.2);
  });
});
