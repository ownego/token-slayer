// @vitest-environment jsdom
import { expect, test } from 'vitest';
import { boardHitHandler, createBoardRenderer, createBoardView } from '@battlefield/hud/board-view.js';
import { rankBoard } from '@battlefield/shared/board.js';

const view = () => {
  document.body.innerHTML = '<div class="bf-board"><ol></ol></div>';

  return createBoardView(document.querySelector('.bf-board'), {
    avatar: () => '', name: id => `#${id}`, color: () => '#fff', isYou: () => false,
  });
};

test('a first hit shows its row and its +N even though the scene counts the damage after the HUD hears the hit', async () => {
  // the HUD binds to the bus before the Phaser scene exists, so its hit
  // handler runs before the scene's own handleHit adds the damage
  const board = view();
  const totals = new Map();
  let prev = new Map();
  const render = () => { const b = rankBoard(prev, totals); prev = new Map(b.rows.map(r => [r.id, r.rank])); board.update(b); };
  const onHit = boardHitHandler(board, render);

  onHit({ user_id: 7, damage: 1234 });
  totals.set(7, 1234); // the scene's handler, registered later on the same bus
  await Promise.resolve();

  const row = document.querySelector('.bf-board li');
  expect(row).not.toBeNull();
  expect(row.querySelector('.plus')?.textContent).toBe('+1.2K');
});

test('the +N reads compact like every other number on the board', () => {
  const board = view();
  board.update(rankBoard(new Map(), new Map([[1, 5000]])));
  board.hit(1, 2_500_000);
  expect(document.querySelector('.plus').textContent).toBe('+2.5M');
});

test('a hit that lands while the scene is still booting leaves the board alone instead of throwing', () => {
  const updates = [];
  const render = createBoardRenderer(() => ({}), { update: b => updates.push(b) });

  expect(() => render()).not.toThrow();
  expect(updates).toEqual([]);
});

test('the renderer keeps the previous ranks so a climb is seen, and forgets them on reset', () => {
  const totals = new Map([[1, 10], [2, 5]]);
  const updates = [];
  const render = createBoardRenderer(() => ({ damageTotals: totals }), { update: b => updates.push(b) });
  render();
  totals.set(2, 50);
  render();
  expect(updates[1].rows.find(r => r.id === 2).climbed).toBe(1);
  render.reset();
  render();
  expect(updates[2].rows.every(r => r.climbed === 0)).toBe(true);
});

test('every row shows its share bar in the fighter\'s colour from its very first render', () => {
  const board = view();
  board.update(rankBoard(new Map(), new Map([[1, 800], [2, 200]])));
  const bars = [...document.querySelectorAll('.bf-board li .nm em')];
  expect(bars.map(b => b.style.width)).toEqual(['100%', '25%']);
  expect(bars.every(b => b.style.background !== '')).toBe(true);
});

test('a hitter with no fighter on the field is named from the hit itself, not shown as #id', async () => {
  const board = view();
  const totals = new Map();
  const render = () => board.update(rankBoard(new Map(), totals));
  const onHit = boardHitHandler(board, render);

  onHit({ user_id: 108, damage: 900, slack_handle: 'smw-test-b' });
  totals.set(108, 900);
  await Promise.resolve();

  expect(document.querySelector('.bf-board li .nm span').textContent).toBe('smw-test-b');
});

test('after a reload, hitters with no fighter on the field are named from the boot leaderboard before they hit again', () => {
  document.body.innerHTML = '<div class="bf-board"><ol></ol></div>';
  const board = createBoardView(document.querySelector('.bf-board'), {
    avatar: () => '', name: id => `#${id}`, color: () => '#fff', isYou: () => false,
    handles: [{ userId: 108, handle: 'smw-test-b' }],
  });
  board.update(rankBoard(new Map(), new Map([[108, 500]])));
  expect(document.querySelector('.bf-board li .nm span').textContent).toBe('smw-test-b');
});
