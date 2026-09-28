// @vitest-environment jsdom
import { beforeEach, expect, test } from 'vitest';
import { createBossPlate, hitChips } from '@battlefield/hud/boss-plate.js';

beforeEach(() => {
  // jsdom has no Web Animations
  Element.prototype.animate = function () { return { onfinish: null }; };
});

test('every hit chips a few shards off the bar, more for a bigger hit, even far from a 10% mark', () => {
  expect(hitChips(0)).toBe(0);
  expect(hitChips(2_000)).toBeGreaterThanOrEqual(2);
  expect(hitChips(400_000)).toBeGreaterThan(hitChips(20_000));
  expect(hitChips(1e12)).toBeLessThanOrEqual(8);
});

test('a hit sprays its chips from the bar\'s live edge', () => {
  document.body.innerHTML = `<section class="bf-plate"><div class="bp-name"><span></span><small class="bp-num"></small></div>
    <div class="hpbar"><span class="lag"></span><span class="fill"></span><span class="hp-t"></span></div></section>`;
  const root = document.querySelector('.bf-plate');
  const plate = createBossPlate(root);
  plate.spawn('Cthulhu', 97, 97_000_000, 43_000_000);

  plate.hit(50_000);

  expect(root.querySelectorAll('.shard').length).toBe(hitChips(50_000));
});

test('after the load intro, the first hit cracks nothing the boss had already lost before the page loaded', () => {
  document.body.innerHTML = `<section class="bf-plate"><div class="bp-name"><span></span><small class="bp-num"></small></div>
    <div class="hpbar"><span class="lag"></span><span class="fill"></span><span class="hp-t"></span></div></section>`;
  const root = document.querySelector('.bf-plate');
  let now = 0;
  const frames = [];
  globalThis.requestAnimationFrame = fn => frames.push(fn);
  globalThis.performance.now = () => now;
  const plate = createBossPlate(root);
  plate.spawn('Cthulhu', 97, 97_000_000, 43_000_000);
  plate.intro(100);
  now = 200;
  frames.splice(0).forEach(fn => fn(now));

  plate.set(42_950_000, 97_000_000);

  expect(root.querySelectorAll('.shard').length).toBe(0);
});
