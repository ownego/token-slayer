import { beforeEach, describe, expect, test } from 'vitest';
import { DRAFT_KEY, registerCustomRoster, CUSTOM_KEY } from '@battlefield/sheet/custom-roster.js';
import { frameOffset, inspectorStart, inspectorStep } from '@battlefield/sheet/frame-inspector.js';

const WIRE = {
  version: 'abc123def456',
  name: 'Sir Pixel',
  url_base: '/storage/custom-characters/7/staged/abc123def456',
  animations: {
    idle: { frames: 6, rate: 8 },
    walk: { frames: 8, rate: 10 },
    attack: { frames: 6, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
};

describe('a draft registered next to the player\'s fighter', () => {
  let byKey;
  let box;

  beforeEach(() => {
    byKey = {};
    box = {};
  });

  test('lives under its own key, so the fighter on the roster is left alone', () => {
    registerCustomRoster(byKey, box, { ...WIRE, version: 'live', url_base: '/storage/custom-characters/7/live' });

    expect(registerCustomRoster(byKey, box, WIRE, DRAFT_KEY)).toBe(true);

    expect(DRAFT_KEY).not.toBe(CUSTOM_KEY);
    expect(byKey[DRAFT_KEY].key).toBe(DRAFT_KEY);
    expect(byKey[DRAFT_KEY].anims.idle.file).toBe('/storage/custom-characters/7/staged/abc123def456/idle.png');
    expect(byKey[CUSTOM_KEY].anims.idle.file).toContain('/live');
    expect(box[DRAFT_KEY]).toBeDefined();
  });

  test('is taken out again with null, without touching the fighter', () => {
    registerCustomRoster(byKey, box, WIRE);
    registerCustomRoster(byKey, box, WIRE, DRAFT_KEY);

    registerCustomRoster(byKey, box, null, DRAFT_KEY);

    expect(byKey[DRAFT_KEY]).toBeUndefined();
    expect(box[DRAFT_KEY]).toBeUndefined();
    expect(byKey[CUSTOM_KEY]).toBeDefined();
  });
});

describe('stepping through a strip a frame at a time', () => {
  test('goes forward and back, wrapping at both ends', () => {
    expect(inspectorStep(inspectorStart(), 1, 6).frame).toBe(1);
    expect(inspectorStep({ ...inspectorStart(), frame: 5 }, 1, 6).frame).toBe(0);
    expect(inspectorStep(inspectorStart(), -1, 6).frame).toBe(5);
    expect(inspectorStep({ ...inspectorStart(), frame: 3 }, -1, 6).frame).toBe(2);
  });

  test('puts a frame on the stage by its 100 px slot', () => {
    expect(frameOffset(0)).toBe(0);
    expect(frameOffset(3)).toBe(-300);
  });
});
