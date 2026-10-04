// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { createBossPlate } from '@battlefield/hud/boss-plate.js';
import { countTo } from '@battlefield/hud/index.js';
import { revealHud } from '@battlefield/hud/reveal.js';

let now = 0;
let frames = [];
beforeEach(() => {
  now = 0;
  frames = [];
  vi.stubGlobal('performance', { now: () => now });
  vi.stubGlobal('requestAnimationFrame', fn => { frames.push(fn); return frames.length; });
  vi.stubGlobal('cancelAnimationFrame', () => {});
});
afterEach(() => vi.unstubAllGlobals());

/** Advances fake time and runs the frames queued so far. */
function advance(ms) {
  now += ms;
  const run = frames;
  frames = [];
  run.forEach(fn => fn(now));
}

function plateDom() {
  document.body.innerHTML = `<section class="bf-plate"><div class="bp-name"><span></span><small class="bp-num"></small></div>
    <div class="hpbar"><span class="lag"></span><span class="fill"></span><span class="hp-t"></span></div></section>`;

  return document.querySelector('.bf-plate');
}

test('on load the HP bar fills up from empty to the boss\'s current HP, its number counting alongside', () => {
  const root = plateDom();
  const plate = createBossPlate(root);
  plate.spawn('Cthulhu', 37, 1000, 600);

  plate.intro(1000);
  expect(root.querySelector('.fill').style.width).toBe('0%');

  advance(500);
  const mid = parseFloat(root.querySelector('.fill').style.width);
  expect(mid).toBeGreaterThan(0);
  expect(mid).toBeLessThan(60);

  advance(600);
  expect(parseFloat(root.querySelector('.fill').style.width)).toBe(60);
  expect(root.querySelector('.hp-t').textContent).toBe('600 / 1K');
});

test('countTo takes its own duration, so the load intro can count slower than a live hit', () => {
  const el = document.createElement('b');
  countTo(el, 1000, v => String(Math.round(v)), 1200);

  advance(420); // a live hit's whole count would be over by now
  expect(Number(el.textContent)).toBeLessThan(1000);
  advance(800);
  expect(el.textContent).toBe('1000');
});

test('the HUD reveals once, panel by panel', () => {
  document.body.innerHTML = '<div id="bf-hud" class="bf-hud"><div class="bf-hud-in"><nav></nav><section></section><section></section></div></div>';
  const hud = document.getElementById('bf-hud');

  revealHud(hud);

  expect(hud.classList.contains('ready')).toBe(true);
  expect([...hud.querySelectorAll('.bf-hud-in > *')].map(el => el.style.getPropertyValue('--i'))).toEqual(['0', '1', '2']);
});

test('team and board inside the stats wrapper slide in as panels of their own', () => {
  document.body.innerHTML = '<div id="bf-hud" class="bf-hud"><div class="bf-hud-in"><nav></nav><div class="bf-stats"><button></button><section class="t"></section><section class="b"></section></div><section class="f"></section></div></div>';
  const hud = document.getElementById('bf-hud');

  revealHud(hud);

  expect(['nav', '.bf-stats > .t', '.bf-stats > .b', '.f'].map(sel => hud.querySelector(sel).style.getPropertyValue('--i')))
    .toEqual(['0', '1', '2', '3']);
});
