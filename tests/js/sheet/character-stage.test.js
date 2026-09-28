// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { characterStage, mountCharacterStage } from '@battlefield/sheet/character-stage.js';

const IDS = ['show-stage', 'flames', 'tick-ring', 'shock', 'fx-circle', 'fx-burst', 'show-sprite', 'stage-avatar', 'show-effect', 'boss', 'hit-spark', 'flashbang', 'dmg-layer', 'banner', 'show-name', 'show-type', 'also', 'show-state', 'hotbar', 'move-name', 'equip-note', 'back-equipped', 'equip-btn', 'equip-label'];

let cleanup = [];

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({ matches: true }));
  vi.stubGlobal('requestAnimationFrame', () => 0);
  vi.stubGlobal('cancelAnimationFrame', () => {});
  document.body.innerHTML = `<div class="tab-panel"><div id="root">
    <aside class="roster"><span id="roster-count"></span>
      <div class="chips"><button class="chip" data-type="">All</button><button class="chip" data-type="ARROW">Arrow</button></div>
      <div id="roster-grid"></div></aside>
    <section class="showcase">${IDS.map(id => `<span id="${id}"></span>`).join('')}<div class="floor"></div></section>
  </div></div><div id="toast"></div><div id="tipbox"></div>`;
  // the stage's own floor lives inside #show-stage in the real markup
  document.getElementById('show-stage').appendChild(document.querySelector('.floor'));
});

afterEach(() => {
  cleanup.forEach(fn => fn());
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const mount = (overrides = {}) => {
  const equip = vi.fn(() => Promise.resolve());
  const dispatch = vi.fn();
  cleanup = mountCharacterStage(document.getElementById('root'), { equipped: 'orc-rider', roommates: {}, handle: 'tungot', equip, dispatch, ...overrides });

  return { equip, dispatch };
};

test('renders one slot per fighter and tags the equipped one YOU', () => {
  mount();

  expect(document.querySelectorAll('.slot')).toHaveLength(20);
  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')?.textContent).toBe('YOU');
  expect(document.getElementById('roster-count').textContent).toBe('20 fighters');
});

test('a teammate playing a fighter shows as a face on its slot', () => {
  mount({ roommates: { wizard: [{ user_id: 3, handle: 'linhpt' }] } });

  expect(document.querySelector('.slot[data-key="wizard"] .mates')?.textContent).toBe('L');
});

test('picking another fighter offers to equip it; equipping folds onto the battlefield, then persists once the camera lands', () => {
  vi.useFakeTimers();
  const { equip, dispatch } = mount();

  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('equip-label').textContent).toBe('Equip Wizard');

  document.getElementById('equip-btn').click();
  expect(document.querySelector('.slot[data-key="wizard"] .tag')).not.toBeNull();
  expect(dispatch).not.toHaveBeenCalled();
  expect(equip).not.toHaveBeenCalled();

  vi.advanceTimersByTime(900);
  expect(dispatch).toHaveBeenCalledWith('fighter-equipped');

  vi.advanceTimersByTime(1300);
  expect(equip).toHaveBeenCalledWith('wizard');
});

test('undo before the swap lands sends nothing; undo after it re-equips the old fighter', () => {
  vi.useFakeTimers();
  const { equip } = mount();
  document.querySelector('.slot[data-key="wizard"]').click();
  document.getElementById('equip-btn').click();

  document.querySelector('#toast .undo').click();
  vi.advanceTimersByTime(5000);
  expect(equip).not.toHaveBeenCalled();
  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')).not.toBeNull();

  document.querySelector('.slot[data-key="wizard"]').click();
  document.getElementById('equip-btn').click();
  vi.advanceTimersByTime(2200);
  document.querySelector('#toast .undo').click();
  expect(equip.mock.calls).toEqual([['wizard'], ['orc-rider']]);
});

test('a style chip hides the other styles and recounts the roster', () => {
  mount();

  document.querySelector('.chip[data-type="ARROW"]').click();

  expect(document.getElementById('roster-count').textContent).toBe('3 fighters');
  expect(document.querySelector('.slot[data-key="wizard"]').classList.contains('filtered-out')).toBe(true);
});

test('the viewer never shows up as their own teammate', () => {
  mount({ me: 7, roommates: { 'orc-rider': [{ user_id: 7, handle: 'tungot' }, { user_id: 3, handle: 'huytv' }] } });

  expect(document.querySelector('.slot[data-key="orc-rider"] .mates')?.textContent).toBe('H');
  expect(document.getElementById('also').textContent).toMatch(/huytv\s+fights as this too/);
  expect(document.getElementById('also').textContent).not.toContain('tungot');
});

test('equip persists through the component itself, found by id, after the sheet (and its element) is gone', () => {
  // Livewire's $wire magic resolves its component lazily from the element;
  // by the time Equip persists, close() has detached that element, so a
  // $wire captured at init resolves to nothing and the call is lost.
  const equip = vi.fn();
  // Livewire.find(id) returns the component's $wire proxy itself (checked on staging)
  vi.stubGlobal('Livewire', { find: id => (id === 'abc' ? { $id: 'abc', equip } : null) });
  const stage = characterStage('orc-rider', {}, 'tungot', 1);
  stage.$el = document.getElementById('root');
  stage.$wire = { $id: 'abc', equip: () => { throw new Error('stale $wire used'); } };
  stage.$dispatch = vi.fn();
  vi.useFakeTimers();
  stage.init();
  cleanup = stage.cleanup;

  document.querySelector('.slot[data-key="wizard"]').click();
  document.getElementById('equip-btn').click();
  vi.advanceTimersByTime(2200);

  expect(equip).toHaveBeenCalledWith('wizard');
});

test('many players on one fighter: the stage line names two and counts the rest', () => {
  const players = ['linhpt', 'sonnh', 'huytv', 'giaolx', 'trangnt'].map((handle, i) => ({ user_id: i + 10, handle }));
  mount({ me: 1, roommates: { 'orc-rider': players } });

  const also = document.getElementById('also').textContent;
  expect(also).toContain('linhpt, sonnh +3');
  expect(also).not.toContain('trangnt');
  expect(document.querySelectorAll('#also .mate')).toHaveLength(2);
});

test('the roster tooltip goes away on click and when the tab is torn down, instead of sticking on screen', () => {
  mount();
  const slot = document.querySelector('.slot[data-key="wizard"]');
  const tip = document.getElementById('tipbox');

  slot.dispatchEvent(new Event('pointerover', { bubbles: true }));
  expect(tip.classList.contains('show')).toBe(true);
  slot.dispatchEvent(new Event('pointerdown', { bubbles: true }));
  expect(tip.classList.contains('show')).toBe(false);

  slot.dispatchEvent(new Event('pointerover', { bubbles: true }));
  cleanup.forEach(fn => fn());
  cleanup = [];
  expect(tip.classList.contains('show')).toBe(false);
});

test('a teammate on a fighter shows their real avatar over the initial', () => {
  mount({ roommates: { wizard: [{ user_id: 3, handle: 'linhpt', avatar: '/avatars/3?v=abc' }] } });

  const face = document.querySelector('.slot[data-key="wizard"] .mates .mate');
  expect(face.querySelector('img')?.getAttribute('src')).toBe('/avatars/3?v=abc');
  expect(face.textContent).toBe('L');
});
