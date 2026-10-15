// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { characterStage, mountCharacterStage } from '@battlefield/sheet/character-stage.js';

const IDS = ['show-stage', 'flames', 'tick-ring', 'shock', 'fx-circle', 'fx-burst', 'show-sprite', 'stage-avatar', 'show-effect', 'boss', 'hit-spark', 'flashbang', 'dmg-layer', 'banner', 'show-name', 'show-type', 'also', 'show-state', 'hotbar', 'move-name', 'equip-note', 'back-equipped', 'edit-custom', 'equip-btn', 'equip-label'];

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
    <section class="custom-panel" id="custom-panel" hidden><button type="button" id="panel-back">Back</button></section>
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

const CUSTOM = {
  version: 'abc123def456',
  url_base: '/storage/custom-characters/7/abc123def456',
  animations: {
    idle: { frames: 6, rate: 8 },
    walk: { frames: 8, rate: 10 },
    attack: { frames: 6, rate: 12 },
    death: { frames: 3, rate: 6 },
  },
};

test('the roster starts with a Create tile that opens the editor panel in place, and it is not a fighter slot', () => {
  mount();

  const tile = document.querySelector('#roster-grid').firstElementChild;
  expect(tile.tagName).toBe('BUTTON');
  expect(tile.classList.contains('slot-add')).toBe(true);
  expect(tile.classList.contains('slot')).toBe(false);
  expect(tile.textContent).toContain('Create');
  expect(tile.getAttribute('href')).toBeNull();
  expect(document.querySelectorAll('.slot')).toHaveLength(20);
  expect(document.getElementById('custom-panel').hidden).toBe(true);

  tile.click();

  expect(document.getElementById('custom-panel').hidden).toBe(false);
  expect(document.querySelector('.showcase').hidden).toBe(true);
  expect(tile.getAttribute('aria-pressed')).toBe('true');
});

test('the tile reads Manage once there is a custom fighter', () => {
  mount({ custom: CUSTOM });

  expect(document.querySelector('.slot-add').textContent).toContain('Manage');
});

test('the back button, and picking any fighter, bring the stage back', () => {
  mount({ custom: CUSTOM });
  const tile = document.querySelector('.slot-add');

  tile.click();
  document.getElementById('panel-back').click();
  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(document.querySelector('.showcase').hidden).toBe(false);

  tile.click();
  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(document.querySelector('.showcase').hidden).toBe(false);
  expect(document.getElementById('show-name').textContent).toBe('Wizard');
});

test('the tile toggles the panel: pressing it again closes it', () => {
  mount();
  const tile = document.querySelector('.slot-add');

  tile.click();
  tile.click();

  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(tile.getAttribute('aria-pressed')).toBe('false');
});

test('the custom slot carries the name the player gave and an always-visible CUSTOM badge', () => {
  mount({ custom: { ...CUSTOM, name: 'Sir Pixel' } });

  const slot = document.querySelector('.slot[data-key="custom"]');
  expect(slot.querySelector('.nm').textContent).toBe('Sir Pixel');
  expect(slot.querySelector('.badge')?.textContent).toBe('CUSTOM');
  expect(slot.getAttribute('aria-label')).toBe('Sir Pixel (custom)');
});

test('a name is shown as text, never as markup', () => {
  mount({ custom: { ...CUSTOM, name: '<img src=x onerror=alert(1)>' } });

  const slot = document.querySelector('.slot[data-key="custom"]');
  expect(slot.querySelector('.nm').textContent).toBe('<img src=x onerror=alert(1)>');
  expect(slot.querySelector('.nm img')).toBeNull();
});

test('the stage names the custom fighter by its name, and offers to edit it', () => {
  mount({ equipped: 'custom', custom: { ...CUSTOM, name: 'Sir Pixel' } });

  expect(document.getElementById('show-name').textContent).toBe('Sir Pixel');
  expect(document.getElementById('edit-custom').hidden).toBe(false);

  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('edit-custom').hidden).toBe(true);

  document.querySelector('.slot[data-key="custom"]').click();
  document.getElementById('edit-custom').click();
  expect(document.getElementById('custom-panel').hidden).toBe(false);
});

const changed = detail => window.dispatchEvent(new CustomEvent('custom-character-changed', { detail }));

test('a fighter made in the editor appears as a slot, equipped, with no reload', () => {
  mount({ equipped: 'orc-rider' });
  expect(document.querySelector('.slot[data-key="custom"]')).toBeNull();

  changed({ custom: { ...CUSTOM, name: 'Moss', active: true }, equipped: 'custom' });

  const slot = document.querySelector('.slot[data-key="custom"]');
  expect(slot).not.toBeNull();
  expect(slot.querySelector('.nm').textContent).toBe('Moss');
  expect(slot.querySelector('.tag')?.textContent).toBe('YOU');
  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')).toBeNull();
  expect(document.querySelector('.slot-add').textContent).toContain('Manage');
  expect(document.getElementById('roster-count').textContent).toBe('21 fighters');
});

test('renaming or replacing the fighter updates its slot in place, never adding a second one', () => {
  mount({ equipped: 'custom', custom: CUSTOM });

  changed({ custom: { ...CUSTOM, version: 'newversion123', name: 'Renamed', active: true }, equipped: 'custom' });

  expect(document.querySelectorAll('.slot[data-key="custom"]')).toHaveLength(1);
  expect(document.querySelector('.slot[data-key="custom"] .nm').textContent).toBe('Renamed');
  expect(document.getElementById('show-name').textContent).toBe('Renamed');
});

test('removing the fighter drops the slot and puts the player back on their roster character', () => {
  mount({ equipped: 'custom', custom: CUSTOM });

  changed({ custom: null, equipped: 'knight' });

  expect(document.querySelector('.slot[data-key="custom"]')).toBeNull();
  expect(document.querySelector('.slot[data-key="knight"] .tag')?.textContent).toBe('YOU');
  expect(document.getElementById('show-name').textContent).toBe('Knight');
  expect(document.querySelector('.slot-add').textContent).toContain('Create');
});

test('a fighter that is not equipped keeps its slot but not the YOU tag', () => {
  mount({ equipped: 'custom', custom: CUSTOM });

  changed({ custom: { ...CUSTOM, active: false }, equipped: 'orc-rider' });

  expect(document.querySelector('.slot[data-key="custom"]')).not.toBeNull();
  expect(document.querySelector('.slot[data-key="custom"] .tag')).toBeNull();
  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')?.textContent).toBe('YOU');
});

test('the event is ignored once the sheet is gone (torn down), so a stale listener cannot touch a later one', () => {
  mount({ equipped: 'orc-rider' });
  cleanup.forEach(fn => fn());
  cleanup = [];

  expect(() => changed({ custom: CUSTOM, equipped: 'custom' })).not.toThrow();
  expect(document.querySelector('.slot[data-key="custom"]')).toBeNull();
});

test('someone with no custom fighter gets no custom slot', () => {
  mount();

  expect(document.querySelector('.slot[data-key="custom"]')).toBeNull();
});

test('a custom fighter is a slot next to the roster, tagged YOU when it is the equipped one', () => {
  mount({ equipped: 'custom', custom: CUSTOM });

  const slot = document.querySelector('.slot[data-key="custom"]');
  expect(slot).not.toBeNull();
  expect(slot.querySelector('.tag')?.textContent).toBe('YOU');
  expect(document.querySelectorAll('.slot')).toHaveLength(21);
  expect(document.getElementById('roster-count').textContent).toBe('21 fighters');
  expect(document.getElementById('show-name').textContent).toBe('Custom');
  expect(document.getElementById('show-state').textContent).toBe('Equipped');
});

test('a custom fighter that is not equipped can be picked and equipped like any other', () => {
  vi.useFakeTimers();
  const { equip } = mount({ equipped: 'orc-rider', custom: CUSTOM });

  document.querySelector('.slot[data-key="custom"]').click();
  expect(document.getElementById('equip-label').textContent).toBe('Equip Custom');
  document.getElementById('equip-btn').click();
  vi.advanceTimersByTime(2300);

  expect(equip).toHaveBeenCalledWith('custom');
  expect(document.querySelector('.slot[data-key="custom"] .tag')).not.toBeNull();
});

test('its moves are idle, walk, its one attack, death and summon', () => {
  mount({ equipped: 'custom', custom: CUSTOM });

  expect([...document.querySelectorAll('#hotbar .move')].map(m => m.dataset.id)).toEqual(['idle', 'walk', 'attack1', 'death', 'summon']);
});

test('a style chip leaves the custom slot showing, since it fights as nothing in particular', () => {
  mount({ custom: CUSTOM });

  document.querySelector('.chip[data-type="ARROW"]').click();

  expect(document.querySelector('.slot[data-key="custom"]').classList.contains('filtered-out')).toBe(false);
});

test('an equipped key the roster does not know falls back to the first fighter, not a crash', () => {
  mount({ equipped: 'custom' });

  expect(document.querySelectorAll('.slot')).toHaveLength(20);
  expect(document.getElementById('show-name').textContent).toBe('Soldier');
});

test('arrow keys walk the fighters and skip the + Custom tile', () => {
  mount({ custom: CUSTOM });
  const first = document.querySelector('.slot[data-key="custom"]');
  first.focus();

  first.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }));

  expect(document.activeElement.dataset.key).toBe('soldier');
});
