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

describe('miniStage with a set of custom minions', () => {
  const minion = (version, frames = 6) => ({
    id: 1,
    version,
    url_base: `/storage/custom-minions/7/${version}`,
    body_px: 22,
    source: 'upload',
    animations: { idle: { frames, rate: 8 }, walk: { frames: 8, rate: 16 }, attack: { frames: 6, rate: 12 }, death: { frames: 3, rate: 6 } },
  });

  test('draws the set instead of the default minions when the sheet opens with one', () => {
    const stage = miniStage('knight', 2, 7, null, [minion('aaaaaaaaaaaa')]);

    expect(stage.minionSpots.map(s => s.kind)).toEqual(['m-custom', 'm-custom']);
    expect(stage.minionSpots[0].look).toEqual({ url: '/storage/custom-minions/7/aaaaaaaaaaaa/idle.png', frames: 6, rate: 8 });
  });

  test('keeps the defaults for an empty set or none', () => {
    const stage = miniStage('knight', 2, 7, null, []);

    expect(stage.minionSpots.map(s => s.kind)).toEqual(['m-goomba', 'm-demon']);
    expect(miniStage('knight', 2, 7).looks).toEqual([]);
  });

  test('follows the editor: a set that changes redraws the swarm at once', () => {
    const stage = miniStage('knight', 2, 7, null, []);

    stage.onMinionsChanged([minion('aaaaaaaaaaaa'), minion('bbbbbbbbbbbb', 4)]);
    expect(stage.minionSpots.map(s => s.look.frames)).toEqual([6, 4]);

    stage.onMinionsChanged([]);
    expect(stage.minionSpots.map(s => s.kind)).toEqual(['m-goomba', 'm-demon']);
  });

  test('a change with no information in it leaves the set alone', () => {
    const stage = miniStage('knight', 2, 7, null, [minion('aaaaaaaaaaaa')]);

    stage.onMinionsChanged(undefined);

    expect(stage.looks).toHaveLength(1);
  });

  test('a broadcast of this player\'s set redraws it and asks the sheet to refresh its editor; another player\'s does neither', () => {
    const stage = miniStage('knight', 2, 7, null, []);
    const own = [];
    window.addEventListener('sheet-own-minions-broadcast', event => own.push(event.detail));

    stage.onMinionsBroadcast({ user_id: 8, custom_minions: [minion('aaaaaaaaaaaa')] });
    expect(stage.looks).toEqual([]);
    expect(own).toEqual([]);

    stage.onMinionsBroadcast({ user_id: 7, custom_minions: [minion('aaaaaaaaaaaa')] });
    expect(stage.looks).toHaveLength(1);
    expect(own).toEqual([{ user_id: 7 }]);
  });

  test('styles a custom minion by its strip, frame count and rate, as properties not as text', () => {
    const stage = miniStage('knight', 1, 7, null, [minion('aaaaaaaaaaaa')]);
    const style = stage.spotStyle({ x: -72, y: 0, look: stage.looks[0] });

    expect(style).toMatchObject({ '--mx': '-72px', '--my': '0px', '--frames': 6, '--rate': 8 });
    expect(style['--strip']).toBe('url("/storage/custom-minions/7/aaaaaaaaaaaa/idle.png")');
    expect(stage.spotStyle({ x: 70, y: 0 })).toEqual({ '--mx': '70px', '--my': '0px' });
  });
});
