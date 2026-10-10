import { describe, expect, test } from 'vitest';
import { LAYOUTS, layoutFor, needsRelayout } from '@battlefield/config.js';

describe('layoutFor — landscape', () => {
  test('a 16:9 desktop keeps the authored 960x540 world exactly', () => {
    const L = layoutFor({ width: 1920, height: 1080 });

    expect(L.mode).toBe('landscape');
    expect(L.logicalWidth).toBe(960);
    expect(L.logicalHeight).toBe(540);
    expect(L.boss.anchor).toEqual(LAYOUTS.landscape.boss.anchor);
    expect(L.fighters.rowXRange).toEqual([80, 880]);
    expect(L.fighters.perRowMax).toBe(14);
  });

  test('a phone on its side widens the world to its own aspect instead of pillarboxing', () => {
    const L = layoutFor({ width: 844, height: 390 });

    expect(L.logicalHeight).toBe(540);
    expect(L.logicalWidth).toBe(1169);
  });

  test('the extra width is shared evenly: boss, HP bar and bats stay centred', () => {
    const L = layoutFor({ width: 844, height: 390 });
    const centre = L.logicalWidth / 2;

    expect(L.boss.anchor.x).toBeCloseTo(centre, 0);
    expect(L.hpBar.x).toBeCloseTo(centre, 0);
    expect(L.bats.centerX).toBeCloseTo(centre, 0);
    expect(L.boss.anchor.y).toBe(LAYOUTS.landscape.boss.anchor.y);
  });

  test('the fighter row spans the wider world and fits more fighters per row', () => {
    const L = layoutFor({ width: 844, height: 390 });

    expect(L.fighters.rowXRange).toEqual([80, L.logicalWidth - 80]);
    expect(L.fighters.perRowMax).toBeGreaterThan(14);
  });

  test('ultra-wide screens are clamped at 1240 wide', () => {
    expect(layoutFor({ width: 3440, height: 1000 }).logicalWidth).toBe(1240);
  });

  test('a 4:3 tablet on its side gets a taller world instead of letterbox bars, everything centred in it', () => {
    const L = layoutFor({ width: 1024, height: 768 });

    expect(L.logicalWidth).toBe(960);
    expect(L.logicalHeight).toBe(720);
    expect(L.shiftY).toBe(90);
    expect(L.boss.anchor.y).toBe(LAYOUTS.landscape.boss.anchor.y + 90);
    expect(L.hpBar.y).toBe(LAYOUTS.landscape.hpBar.y + 90);
  });

  test('the boss drops below a boss plate that reaches into it on a short screen', () => {
    const L = layoutFor({ width: 844, height: 390, hudBand: 113 });
    const bossTop = L.boss.anchor.y - 110;

    expect(bossTop).toBeGreaterThanOrEqual(113);
    expect(L.hpBar.y).toBe(LAYOUTS.landscape.hpBar.y + L.shiftY);
  });

  test('a desktop plate that stays above the boss moves nothing', () => {
    expect(layoutFor({ width: 1920, height: 1080, hudBand: 50 }).shiftY).toBe(0);
  });

  test('never mutates the authored LAYOUTS', () => {
    const before = JSON.stringify(LAYOUTS);
    layoutFor({ width: 844, height: 390 });
    layoutFor({ width: 390, height: 844, hudBand: 400 });

    expect(JSON.stringify(LAYOUTS)).toBe(before);
  });
});

describe('layoutFor — portrait', () => {
  test('a tall phone gets a world as tall as its own aspect: no letterbox bars', () => {
    const L = layoutFor({ width: 390, height: 844 });

    expect(L.mode).toBe('portrait');
    expect(L.logicalWidth).toBe(540);
    expect(L.logicalHeight).toBe(1169);
  });

  test('without a measured HUD band the boss sits where it was authored', () => {
    const L = layoutFor({ width: 390, height: 844 });

    expect(L.shiftY).toBe(0);
    expect(L.boss.anchor.y).toBe(LAYOUTS.portrait.boss.anchor.y);
  });

  test('the boss block starts just below the HUD band, so no panel can cover it', () => {
    const L = layoutFor({ width: 390, height: 844, hudBand: 360 });

    expect(L.boss.name.y).toBeGreaterThanOrEqual(360);
    expect(L.shiftY).toBe(L.boss.name.y - LAYOUTS.portrait.boss.name.y);
  });

  test('everything under the boss moves down with it', () => {
    const L = layoutFor({ width: 390, height: 844, hudBand: 360 });
    const base = LAYOUTS.portrait;

    expect(L.hpBar.y).toBe(base.hpBar.y + L.shiftY);
    expect(L.bats.centerY).toBe(base.bats.centerY + L.shiftY);
    expect(L.necromancer.anchor.y).toBe(base.necromancer.anchor.y + L.shiftY);
    expect(L.fighters.rowY).toBe(base.fighters.rowY + L.fighterShiftY);
    expect(L.fighterShiftY).toBeGreaterThanOrEqual(L.shiftY);
  });

  test('on a tall phone the fighter rows sit in the middle of the spare floor, not bunched under the boss', () => {
    const L = layoutFor({ width: 390, height: 844 });
    const spare = L.logicalHeight - LAYOUTS.portrait.logicalHeight;

    expect(L.shiftY).toBe(0);
    expect(L.fighterShiftY).toBe(Math.round(spare / 2));
  });

  test('the rows share only the floor the boss drop left over', () => {
    const L = layoutFor({ width: 390, height: 844, hudBand: 300 });
    const spare = L.logicalHeight - LAYOUTS.portrait.logicalHeight - L.shiftY;

    expect(L.fighterShiftY).toBe(L.shiftY + Math.round(spare / 2));
  });

  test('a short phone still keeps the boss clear of the HUD, borrowing some fighter room', () => {
    const L = layoutFor({ width: 360, height: 640, hudBand: 291 });

    expect(L.logicalHeight).toBe(960);
    expect(L.boss.name.y).toBeGreaterThanOrEqual(291);
  });

  test('but never more than 140 of the authored fighter room', () => {
    expect(layoutFor({ width: 540, height: 960, hudBand: 600 }).shiftY).toBe(140);
  });

  test('a 3:4 tablet gets a wider world instead of side bars, the boss centred in it', () => {
    const L = layoutFor({ width: 768, height: 1024 });

    expect(L.logicalWidth).toBe(720);
    expect(L.logicalHeight).toBe(960);
    expect(L.boss.anchor.x).toBe(360);
    expect(L.fighters.rowXRange).toEqual([50, 670]);
  });
});

describe('needsRelayout', () => {
  const phone = layoutFor({ width: 390, height: 844, hudBand: 300 });

  test('an orientation flip always relayouts', () => {
    expect(needsRelayout(phone, layoutFor({ width: 844, height: 390 }))).toBe(true);
  });

  test('the mobile URL bar showing or hiding (a few % of height) does not reboot the scene', () => {
    expect(needsRelayout(phone, layoutFor({ width: 390, height: 790, hudBand: 300 }))).toBe(false);
  });

  test('a real aspect change does', () => {
    expect(needsRelayout(phone, layoutFor({ width: 390, height: 700, hudBand: 300 }))).toBe(true);
  });

  test('a HUD band that grew down into the boss does, however little', () => {
    expect(needsRelayout(phone, layoutFor({ width: 390, height: 844, hudBand: 312 }))).toBe(true);
  });

  test('a HUD band that shrank a little leaves the boss where it is', () => {
    expect(needsRelayout(phone, layoutFor({ width: 390, height: 844, hudBand: 260 }))).toBe(false);
  });

  test('a HUD band that shrank a lot pulls the boss back up', () => {
    expect(needsRelayout(phone, layoutFor({ width: 390, height: 844, hudBand: 200 }))).toBe(true);
  });
});
