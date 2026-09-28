// @vitest-environment jsdom
import { expect, test } from 'vitest';
import { battlefieldHud } from '@battlefield/hud/index.js';

test('a hit flashes every team total, as the mockup does, so a rising number is noticed', () => {
  document.body.innerHTML = '<div class="bf-team"><div class="t-stat"><b></b></div><div class="t-stat"><b></b></div><div class="t-stat"><b></b></div></div>';
  const hud = Object.assign(battlefieldHud(), { $el: document.body, today: 0, month: 0, allTime: 0, board: new Map() });

  hud.onHit({ user_id: 1, damage: 500 });

  expect([...document.querySelectorAll('.t-stat')].every(el => el.classList.contains('flash'))).toBe(true);
});
