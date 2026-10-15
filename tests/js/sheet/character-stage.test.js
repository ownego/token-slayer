// @vitest-environment jsdom
import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { characterStage, mountCharacterStage } from '@battlefield/sheet/character-stage.js';

const IDS = ['show-stage', 'flames', 'tick-ring', 'shock', 'fx-circle', 'fx-burst', 'show-sprite', 'stage-avatar', 'show-effect', 'boss', 'hit-spark', 'flashbang', 'dmg-layer', 'banner', 'show-name', 'show-type', 'show-author', 'also', 'show-state', 'hotbar', 'move-name', 'equip-note', 'back-equipped', 'edit-custom', 'equip-btn', 'equip-label'];

let cleanup = [];

beforeEach(() => {
  vi.stubGlobal('matchMedia', () => ({ matches: true }));
  vi.stubGlobal('requestAnimationFrame', () => 0);
  vi.stubGlobal('cancelAnimationFrame', () => {});
  document.body.innerHTML = `<div class="tab-panel"><div id="root">
    <aside class="roster"><span id="roster-count"></span>
      <div class="chips"><button class="chip" data-type="">All</button><button class="chip" data-type="CUSTOM" hidden>Custom</button><button class="chip" data-type="ARROW">Arrow</button></div>
      <div id="roster-grid"></div></aside>
    <section class="showcase">${IDS.map(id => `<span id="${id}"></span>`).join('')}<div class="floor"></div><div class="equip-row"></div>
      <button type="button" id="discard-draft" hidden>Discard</button><span id="draft-status" role="status"></span>
      <div id="inspector" hidden><button id="inspect-toggle">Pause</button><button id="inspect-prev">Previous frame</button><button id="inspect-next">Next frame</button><span id="inspect-label"></span></div>
      <section class="cce-drawer" id="custom-panel" hidden><button type="button" id="panel-back">Back</button><div class="cce-row"><button type="button" data-focus="upload">Choose file</button></div><input type="text" data-focus="name"></section>
    </section>
  </div></div><div id="toast"></div><div id="tipbox"></div>`;
  // the stage's own floor lives inside #show-stage in the real markup
  document.getElementById('show-stage').appendChild(document.querySelector('.floor'));
});

afterEach(() => {
  cleanup.forEach(fn => fn());
  delete window.__openCustomEditor;
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

const mount = (overrides = {}) => {
  const equip = vi.fn(() => Promise.resolve());
  const dispatch = vi.fn();
  cleanup = mountCharacterStage(document.getElementById('root'), { equipped: 'orc-rider', roommates: {}, handle: 'tungot', equip, dispatch, ...overrides });

  return { equip, dispatch };
};

test('measures the phone action rows so a toast clears their controls', () => {
  const actions = document.querySelector('.equip-row');
  Object.defineProperty(actions, 'offsetHeight', { value: 56 });
  mount();

  expect(document.getElementById('toast').style.getPropertyValue('--bar-h')).toBe('56px');
  expect(document.querySelector('.showcase').style.getPropertyValue('--actions-h')).toBe('56px');
});

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

const DRAFT = { hash: 'draft-hash', spec: { ...CUSTOM, name: 'New Fighter' } };

test('a saved draft is selected on the shared stage before the editor mounts', () => {
  mount({ draft: DRAFT });

  const slot = document.querySelector('.slot[data-key="custom-draft"]');
  expect(slot).not.toBeNull();
  expect(slot.querySelector('.badge')?.textContent).toBe('DRAFT');
  expect(slot.getAttribute('aria-selected')).toBe('true');
  expect(document.getElementById('show-name').textContent).toBe('New Fighter');
  expect(document.getElementById('equip-label').textContent).toBe('Use this fighter');
  expect(document.getElementById('discard-draft').hidden).toBe(false);
});

test('a new draft closes the drawer, selects its slot and keeps it visible after a style filter', () => {
  mount();
  document.querySelector('.chip[data-type="ARROW"]').click();
  document.querySelector('.slot-add').click();

  changed({ custom: null, draft: DRAFT, equipped: 'orc-rider' });

  const slot = document.querySelector('.slot[data-key="custom-draft"]');
  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(slot.classList.contains('filtered-out')).toBe(false);
  expect(slot.getAttribute('aria-selected')).toBe('true');
  expect(document.activeElement).toBe(slot);
  expect(document.getElementById('show-state').textContent).toBe('Preview');
  expect(document.getElementById('equip-note').textContent).toContain('Not live yet');
});

test('discarding a selected draft restores the equipped fighter', () => {
  mount({ draft: DRAFT });

  changed({ custom: null, draft: null, equipped: 'orc-rider' });

  expect(document.querySelector('.slot[data-key="custom-draft"]')).toBeNull();
  expect(document.querySelector('.slot[data-key="orc-rider"]').getAttribute('aria-selected')).toBe('true');
  expect(document.getElementById('discard-draft').hidden).toBe(true);
  expect(document.getElementById('equip-label').textContent).toBe('Equipped');
});

test('using a draft dispatches its hash and shows an equipping state without equipping a roster slot', () => {
  const dispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch });
  const { equip } = mount({ draft: DRAFT });

  document.getElementById('equip-btn').click();

  expect(dispatch).toHaveBeenCalledWith('custom-draft-confirm', { hash: 'draft-hash' });
  expect(document.getElementById('equip-btn').getAttribute('aria-busy')).toBe('true');
  expect(document.getElementById('equip-label').textContent).toBe('Equipping…');
  expect(equip).not.toHaveBeenCalled();
  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')).not.toBeNull();
});

test('discarding a draft asks the editor to remove it', () => {
  const dispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch });
  mount({ draft: DRAFT });

  document.getElementById('discard-draft').click();

  expect(dispatch).toHaveBeenCalledWith('custom-draft-discard');
});

test('a failed confirm clears the equipping state and shows the error', () => {
  vi.useFakeTimers();
  vi.stubGlobal('Livewire', { dispatch: vi.fn() });
  mount({ draft: DRAFT });
  document.getElementById('equip-btn').click();

  window.dispatchEvent(new CustomEvent('sheet-error', { detail: { message: 'That draft is gone.' } }));

  expect(document.getElementById('equip-btn').getAttribute('aria-busy')).toBe('false');
  expect(document.getElementById('equip-btn').disabled).toBe(false);
  expect(document.getElementById('toast').textContent).toContain('That draft is gone.');
});

test('an unacknowledged draft confirm eventually unlocks the action and asks for fresh state', () => {
  vi.useFakeTimers();
  const livewireDispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch: livewireDispatch });
  mount({ draft: DRAFT });
  document.getElementById('equip-btn').click();

  vi.runOnlyPendingTimers();

  expect(document.getElementById('equip-btn').disabled).toBe(false);
  expect(document.getElementById('toast').textContent).toContain('The equip did not finish');
  expect(livewireDispatch).toHaveBeenCalledWith('custom-character-refresh');
});

test('confirming the draft selects the custom fighter before its equip ceremony', () => {
  vi.useFakeTimers();
  vi.stubGlobal('Livewire', { dispatch: vi.fn() });
  const { dispatch } = mount({ draft: DRAFT });
  document.getElementById('equip-btn').click();

  changed({ custom: { ...CUSTOM, name: 'New Fighter', active: true }, draft: null, equipped: 'custom', ceremony: true });

  expect(document.querySelector('.slot[data-key="custom"] .tag')?.textContent).toBe('YOU');
  expect(document.querySelector('.slot[data-key="custom"]').getAttribute('aria-selected')).toBe('true');
  expect(document.getElementById('banner').textContent).toBe('Now fighting as New Fighter');
  expect(document.getElementById('equip-btn').getAttribute('aria-busy')).toBe('false');
  vi.advanceTimersByTime(900);
  expect(dispatch).toHaveBeenCalledWith('fighter-equipped');
});

test('the shared stage can pause, step, and play a draft after manual frame selection', () => {
  mount({ draft: DRAFT });
  const sprite = document.getElementById('show-sprite');
  const inspector = document.getElementById('inspector');
  expect(inspector.hidden).toBe(false);

  document.getElementById('inspect-toggle').click();
  expect(sprite.style.animationPlayState).toBe('paused');
  document.getElementById('inspect-next').click();
  expect(sprite.style.animation).toBe('none');
  expect(sprite.style.backgroundPosition).toBe('-100px 0px');
  expect(document.getElementById('inspect-label').textContent).toContain('Frame 2');

  document.getElementById('inspect-toggle').click();
  expect(sprite.style.animation).not.toBe('none');
  expect(sprite.style.animationPlayState).toBe('running');
  expect(document.getElementById('inspect-label').textContent).toContain('playing');
});

test('the inspector disappears when a roster fighter is selected', () => {
  mount({ draft: DRAFT });
  document.querySelector('.slot[data-key="wizard"]').click();

  expect(document.getElementById('inspector').hidden).toBe(true);
});

test('an earlier version can be inspected on the stage, equipped, or closed without a server change', () => {
  const livewireDispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch: livewireDispatch });
  mount({ equipped: 'custom', custom: { ...CUSTOM, name: 'Current Fighter' } });

  window.dispatchEvent(new CustomEvent('custom-version-peek', { detail: { hash: 'older-hash', spec: { ...CUSTOM, version: 'older-hash', name: 'Older Fighter' }, number: 1 } }));

  const slot = document.querySelector('.slot[data-key="custom-draft"]');
  expect(slot.querySelector('.badge').textContent).toBe('Version 1');
  expect(slot.getAttribute('aria-selected')).toBe('true');
  expect(document.getElementById('show-name').textContent).toBe('Older Fighter');
  expect(document.getElementById('show-state').textContent).toBe('Preview');
  expect(document.getElementById('equip-label').textContent).toBe('Equip version 1');
  expect(document.getElementById('discard-draft').textContent).toBe('Close preview');
  expect(document.getElementById('inspector').hidden).toBe(false);

  document.getElementById('equip-btn').click();
  expect(livewireDispatch).toHaveBeenCalledWith('custom-version-equip', { hash: 'older-hash' });
  document.getElementById('discard-draft').click();
  expect(livewireDispatch).not.toHaveBeenCalledWith('custom-draft-discard');
  expect(document.querySelector('.slot[data-key="custom-draft"]')).toBeNull();
  expect(document.querySelector('.slot[data-key="custom"]').getAttribute('aria-selected')).toBe('true');
});

test('a real draft replaces a version peek', () => {
  mount({ equipped: 'custom', custom: CUSTOM });
  window.dispatchEvent(new CustomEvent('custom-version-peek', { detail: { hash: 'older-hash', spec: { ...CUSTOM, version: 'older-hash' }, number: 1 } }));

  changed({ custom: CUSTOM, draft: DRAFT, equipped: 'custom' });

  expect(document.querySelector('.slot[data-key="custom-draft"] .badge').textContent).toBe('DRAFT');
  expect(document.getElementById('equip-label').textContent).toBe('Use this fighter');
});

test('another session changing this player requests one fresh sheet state without reacting to teammates', () => {
  vi.useFakeTimers();
  const livewireDispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch: livewireDispatch });
  mount({ me: 7 });

  window.dispatchEvent(new CustomEvent('sheet-own-character-broadcast', { detail: { user_id: 8 } }));
  window.dispatchEvent(new CustomEvent('sheet-own-character-broadcast', { detail: { user_id: 7 } }));
  window.dispatchEvent(new CustomEvent('sheet-own-character-broadcast', { detail: { user_id: 7 } }));
  vi.runOnlyPendingTimers();

  expect(livewireDispatch).toHaveBeenCalledTimes(1);
  expect(livewireDispatch).toHaveBeenCalledWith('custom-character-refresh');
});

test('the editor deep link opens after the stage mounts even when its event arrived first', () => {
  window.__openCustomEditor = true;
  mount();

  expect(document.getElementById('custom-panel').hidden).toBe(false);
  expect(window.__openCustomEditor).toBe(false);

  window.dispatchEvent(new Event('open-custom-editor'));
  expect(document.querySelector('.showcase').classList.contains('editing')).toBe(true);
  window.__openCustomEditor = false;
});

test('the broadcast echo of a just-announced local change does not request another refresh', () => {
  vi.useFakeTimers();
  const livewireDispatch = vi.fn();
  vi.stubGlobal('Livewire', { dispatch: livewireDispatch });
  mount({ me: 7 });
  changed({ custom: CUSTOM, draft: null, equipped: 'custom' });

  window.dispatchEvent(new CustomEvent('sheet-own-character-broadcast', { detail: { user_id: 7 } }));
  vi.runOnlyPendingTimers();

  expect(livewireDispatch).not.toHaveBeenCalledWith('custom-character-refresh');
});

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
  expect(document.querySelector('.showcase').hidden).toBe(false);
  expect(document.querySelector('.showcase').classList.contains('editing')).toBe(true);
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
  expect(document.querySelector('.showcase').classList.contains('editing')).toBe(false);

  tile.click();
  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(document.querySelector('.showcase').classList.contains('editing')).toBe(false);
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

test('a style chip hides the custom fighter: it has a chip of its own now', () => {
  mount({ custom: CUSTOM });

  document.querySelector('.chip[data-type="ARROW"]').click();

  expect(document.querySelector('.slot[data-key="custom"]').classList.contains('filtered-out')).toBe(true);
  expect(document.getElementById('roster-count').textContent).toBe('3 fighters');
});

test('the Custom chip shows only the custom fighter and counts it', () => {
  mount({ custom: CUSTOM });

  document.querySelector('.chip[data-type="CUSTOM"]').click();

  expect(document.querySelector('.slot[data-key="custom"]').classList.contains('filtered-out')).toBe(false);
  expect(document.querySelector('.slot[data-key="wizard"]').classList.contains('filtered-out')).toBe(true);
  expect(document.getElementById('roster-count').textContent).toBe('1 fighter');

  document.querySelector('.chip[data-type=""]').click();
  expect(document.getElementById('roster-count').textContent).toBe('21 fighters');
});

test('the Custom chip appears only when there is a custom fighter, and goes with it', () => {
  mount();
  expect(document.querySelector('.chip[data-type="CUSTOM"]').hidden).toBe(true);

  changed({ custom: { ...CUSTOM, name: 'Sir Pixel' }, equipped: 'custom' });
  expect(document.querySelector('.chip[data-type="CUSTOM"]').hidden).toBe(false);

  changed({ custom: null, equipped: 'orc-rider' });
  expect(document.querySelector('.chip[data-type="CUSTOM"]').hidden).toBe(true);
});

test('the stage and the slot name the author of a custom fighter, as plain text', () => {
  mount({ equipped: 'custom', custom: { ...CUSTOM, name: 'Sir Pixel', author: 'a&b' } });

  expect(document.getElementById('show-type').textContent).toBe('Slash fighter. Hits up close with sweeping cuts.');
  expect(document.getElementById('show-author').textContent).toBe('Created by a&b');
  const slot = document.querySelector('.slot[data-key="custom"]');
  expect(slot.getAttribute('aria-label')).toBe('Sir Pixel (custom, by a&b)');
  expect(slot.title).toBe('Sir Pixel (custom, by a&b)');

  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('show-author').hidden).toBe(true);
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

test('a result line from the editor shows in the sheet\'s toast, as text', () => {
  vi.useFakeTimers();
  mount();

  window.dispatchEvent(new CustomEvent('sheet-toast', { detail: { message: 'Name saved <b>x</b>' } }));

  const toast = document.getElementById('toast');
  expect(toast.classList.contains('show')).toBe(true);
  expect(toast.textContent).toBe('Name saved <b>x</b>');
  expect(toast.querySelector('b')).toBeNull();
});

test('an equip the server turned away puts the sheet back on the fighter it still has, and says why', () => {
  const { equip } = mount({ equipped: 'orc-rider' });
  document.querySelector('.slot[data-key="wizard"]').click();
  document.getElementById('equip-btn').click();
  expect(document.querySelector('.slot[data-key="wizard"] .tag')).not.toBeNull();

  window.dispatchEvent(new CustomEvent('sheet-equip-failed', { detail: { message: 'Another build of yours is still running. Try again in a moment.', equipped: 'orc-rider' } }));

  expect(document.querySelector('.slot[data-key="orc-rider"] .tag')?.textContent).toBe('YOU');
  expect(document.querySelector('.slot[data-key="wizard"] .tag')).toBeNull();
  expect(document.getElementById('toast').textContent).toContain('Another build of yours is still running');
  expect(equip).not.toHaveBeenCalledWith('wizard');
});

test('opening the editor keeps the stage in view and opens the drawer under it, with focus inside', () => {
  mount({ custom: CUSTOM });
  const tile = document.querySelector('.slot-add');
  tile.focus();

  tile.click();

  const showcase = document.querySelector('.showcase');
  expect(showcase.classList.contains('editing')).toBe(true);
  expect(showcase.hidden).toBe(false);
  expect(document.getElementById('custom-panel').hidden).toBe(false);
  expect(tile.getAttribute('aria-expanded')).toBe('true');
  expect(tile.getAttribute('aria-controls')).toBe('custom-panel');
  expect(document.activeElement.id).toBe('panel-back');
});

test('closing the drawer puts focus back on what opened it', () => {
  mount({ custom: CUSTOM });
  const tile = document.querySelector('.slot-add');
  tile.focus();
  tile.click();

  document.getElementById('panel-back').click();

  expect(tile.getAttribute('aria-expanded')).toBe('false');
  expect(document.activeElement).toBe(tile);
});

test('once the lazy editor has mounted, focus moves to its file button, but only if it is still in the drawer', async () => {
  mount();
  const panel = document.getElementById('custom-panel');
  panel.querySelector('[data-focus="upload"]').remove(); // the editor is not mounted yet
  document.querySelector('.slot-add').click();

  panel.insertAdjacentHTML('beforeend', '<button type="button" data-focus="upload" id="late">Choose file</button>');
  await Promise.resolve();

  expect(document.activeElement.id).toBe('late');
});

test('Escape closes the drawer first: the shell offers it, only an open drawer takes it, a second Escape is left for the sheet', () => {
  mount({ custom: CUSTOM });
  const tile = document.querySelector('.slot-add');
  tile.focus();
  tile.click();

  const first = new CustomEvent('sheet-escape', { cancelable: true });
  window.dispatchEvent(first);
  expect(first.defaultPrevented).toBe(true);
  expect(document.getElementById('custom-panel').hidden).toBe(true);
  expect(document.activeElement).toBe(tile);

  const second = new CustomEvent('sheet-escape', { cancelable: true });
  window.dispatchEvent(second);
  expect(second.defaultPrevented).toBe(false);
});

test('sheet-focus moves focus to a usable target, opening the drawer when the target lives in it', () => {
  mount({ custom: CUSTOM, equipped: 'custom' });
  const focus = target => window.dispatchEvent(new CustomEvent('sheet-focus', { detail: { target } }));

  focus('upload');
  expect(document.getElementById('custom-panel').hidden).toBe(false);
  expect(document.activeElement.dataset.focus).toBe('upload');

  focus('name');
  expect(document.activeElement.dataset.focus).toBe('name');

  document.getElementById('panel-back').click();
  focus('equipped-slot');
  expect(document.activeElement).toBe(document.querySelector('.slot[data-key="custom"]'));

  focus('opener');
  focus('nonsense');
  expect(document.activeElement).not.toBe(document.body);
});

test('opening Create with nothing built shows an empty stage, not the selected roster fighter, and closing brings it back', () => {
  mount({ equipped: 'orc-rider' });
  document.querySelector('.slot[data-key="wizard"]').click();
  expect(document.getElementById('show-name').textContent).toBe('Wizard');

  document.querySelector('.slot-add').click();
  expect(document.querySelector('.showcase').classList.contains('blank')).toBe(true);
  expect(document.getElementById('show-name').textContent).toBe('Your fighter');

  document.getElementById('panel-back').click();
  expect(document.querySelector('.showcase').classList.contains('blank')).toBe(false);
  expect(document.getElementById('show-name').textContent).toBe('Wizard');
});

test('opening Manage shows the player\'s own fighter on the stage', () => {
  mount({ equipped: 'orc-rider', custom: { ...CUSTOM, name: 'Sir Pixel' } });
  document.querySelector('.slot[data-key="wizard"]').click();

  document.querySelector('.slot-add').click();

  expect(document.getElementById('show-name').textContent).toBe('Sir Pixel');
  expect(document.querySelector('.showcase').classList.contains('blank')).toBe(false);
});

test('Manage shows the player\'s fighter even when a draft is waiting', () => {
  mount({ equipped: 'orc-rider', custom: { ...CUSTOM, name: 'Sir Pixel' }, draft: { hash: 'd'.repeat(12), spec: { ...CUSTOM, name: 'Sir Pixel' } } });
  document.querySelector('.slot[data-key="wizard"]').click();

  document.querySelector('.slot-add').click();

  expect(document.getElementById('show-name').textContent).toBe('Sir Pixel');
  expect(document.querySelector('.slot[aria-selected="true"]').dataset.key).toBe('custom');
});

test('a draft slot that appears never carries the damage-number class (it would be thrown out of the grid)', () => {
  mount({ equipped: 'orc-rider', custom: CUSTOM });

  changed({ custom: CUSTOM, draft: DRAFT, equipped: 'orc-rider', ceremony: false });

  const slot = document.querySelector('.slot[data-key="custom-draft"]');
  expect(slot).not.toBeNull();
  expect(slot.classList.contains('pop')).toBe(false);
});

test('the author gets a line of its own on the stage for a custom fighter, its draft, and nothing for a roster fighter', () => {
  mount({ equipped: 'custom', custom: { ...CUSTOM, name: 'Sir Pixel', author: 'tungot.work' } });
  const line = document.getElementById('show-author');

  expect(line.hidden).toBe(false);
  expect(line.textContent).toBe('Created by tungot.work');

  document.querySelector('.slot[data-key="wizard"]').click();
  expect(line.hidden).toBe(true);
});

test('opening Manage makes the fighter walk instead of standing still', () => {
  mount({ equipped: 'orc-rider', custom: { ...CUSTOM, name: 'Sir Pixel' } });

  document.querySelector('.slot-add').click();

  expect(document.getElementById('move-name').textContent).toContain('Walk');
});
