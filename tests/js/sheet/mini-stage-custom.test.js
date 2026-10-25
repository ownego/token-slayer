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
