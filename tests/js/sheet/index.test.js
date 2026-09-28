// @vitest-environment jsdom
import { beforeEach, expect, test, vi } from 'vitest';
import { agoTicker, clawdBuddyPanel, createSheetShell, fighterSheetShell } from '@battlefield/sheet/index.js';

function buildDom() {
  document.body.innerHTML = `
    <button id="opener">Open</button>
    <div id="root">
      <div role="tablist">
        <button class="sheet-tab" data-tab="profile" aria-selected="true">Profile</button>
        <button class="sheet-tab" data-tab="character" aria-selected="false">Character</button>
      </div>
      <button id="close">Close</button>
    </div>
  `;

  return {
    opener: document.getElementById('opener'),
    root: document.getElementById('root'),
    close: document.getElementById('close'),
  };
}

beforeEach(() => {
  document.body.innerHTML = '';
});

test('Escape closes and focus returns to the opener', () => {
  const { opener, root } = buildDom();
  const onClose = vi.fn();
  opener.focus();

  const shell = createSheetShell(root, { onClose });
  shell.open(opener);

  root.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

  expect(onClose).toHaveBeenCalledTimes(1);
  expect(document.activeElement).toBe(opener);

  shell.destroy();
});

test('clicking a tab or pressing an arrow key switches the active tab', () => {
  const { root } = buildDom();
  const onTab = vi.fn();
  const shell = createSheetShell(root, { onClose: vi.fn(), onTab });

  root.querySelector('[data-tab="character"]').dispatchEvent(new MouseEvent('click', { bubbles: true }));
  expect(onTab).toHaveBeenCalledWith('character');
  // Simulates the Livewire re-render that would follow the real onTab callback.
  root.querySelector('[data-tab="profile"]').setAttribute('aria-selected', 'false');
  root.querySelector('[data-tab="character"]').setAttribute('aria-selected', 'true');

  root.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowLeft', bubbles: true }));
  expect(onTab).toHaveBeenCalledWith('profile');

  shell.destroy();
});

test('Tab at the last focusable element wraps to the first (focus trap)', () => {
  const { root, close } = buildDom();
  const shell = createSheetShell(root, { onClose: vi.fn() });
  shell.open(document.body);

  close.focus();
  const evt = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
  root.dispatchEvent(evt);

  expect(document.activeElement).toBe(root.querySelector('.sheet-tab'));

  shell.destroy();
});

test('equipTransition folds the .sheet child, not the shell\'s own overlay root', () => {
  vi.useFakeTimers();
  document.body.innerHTML = `
    <div id="overlay">
      <div class="sheet">
        <div role="tablist">
          <button class="sheet-tab" data-tab="profile" aria-selected="true">Profile</button>
        </div>
      </div>
    </div>
  `;
  const overlay = document.getElementById('overlay');
  const sheet = overlay.querySelector('.sheet');
  const panel = fighterSheetShell(1);
  panel.$el = overlay;
  panel.$wire = { close: vi.fn(), on: vi.fn() };
  panel.init();

  overlay.dispatchEvent(new CustomEvent('fighter-equipped'));

  expect(sheet.classList.contains('folding')).toBe(true);
  expect(overlay.classList.contains('folding')).toBe(false);

  const hidden = vi.fn();
  window.addEventListener('fighter-sheet-hide', hidden);
  vi.advanceTimersByTime(350);
  // stays folded while the frame plays its exit: un-folding here flashed the
  // sheet back on screen on its way out
  expect(sheet.classList.contains('folding')).toBe(true);
  expect(hidden).toHaveBeenCalledTimes(1);
  window.removeEventListener('fighter-sheet-hide', hidden);

  panel.destroy();
  vi.useRealTimers();
});

test('clawdBuddyPanel exposes combo and bestToday, updated via onComboChange', () => {
  const panel = clawdBuddyPanel(1);
  panel.$el = document.createElement('div');
  panel.init();

  panel.buddy.onHit(50);
  expect(panel.combo).toBe(1);
  expect(panel.bestToday).toBe(1);

  panel.buddy.onHit(50);
  expect(panel.combo).toBe(2);
  expect(panel.bestToday).toBe(2);

  panel.destroy();
});

test('switching tabs never goes through the component\'s same-named $open property', () => {
  const { root } = buildDom();
  const panel = fighterSheetShell(1);
  panel.$el = root;
  // Livewire's $wire exposes the public property `open` (a bool) under the
  // same name as the open() method, and $wire.call() resolves through that
  // same name — so the shell goes through open()'s own event listener.
  panel.$wire = { open: true, call: vi.fn(), dispatchSelf: vi.fn(), selectTab: vi.fn(), close: vi.fn(), on: vi.fn() };
  panel.init();

  root.querySelector('[data-tab="character"]').click();
  root.dispatchEvent(new CustomEvent('sheet-open-tab', { detail: { tab: 'profile' } }));

  expect(panel.$wire.selectTab.mock.calls).toEqual([['character'], ['profile']]);
  expect(panel.$wire.call).not.toHaveBeenCalled();
  expect(panel.$wire.dispatchSelf).not.toHaveBeenCalled();
  panel.destroy();
});

test('the refresh stamp ticks from the probe time the server last rendered', () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-09-27T12:00:00Z'));
  const el = document.createElement('span');
  el.dataset.since = String(Date.parse('2026-09-27T11:58:00Z') / 1000);
  const ticker = agoTicker();
  ticker.$el = el;
  ticker.init();
  expect(ticker.label).toBe('2m ago');

  vi.advanceTimersByTime(60_000);
  expect(ticker.label).toBe('3m ago');

  el.dataset.since = String(Date.parse('2026-09-27T12:01:00Z') / 1000); // a refresh re-rendered it
  ticker.tick();
  expect(ticker.label).toBe('just now');

  ticker.destroy();
  vi.useRealTimers();
});

/**
 * Mounts fighterSheetShell over the given markup with a fake battlefield bus,
 * returning the handlers the shell bound.
 */
function mountShell(html, me = 1) {
  document.body.innerHTML = `<div id="fs" class="fs">${html}</div>`;
  const bound = {};
  window.__battlefield = { bus: {}, bindBus: (bus, handlers) => { Object.assign(bound, handlers); return () => {}; } };
  const shell = fighterSheetShell(me);
  shell.$el = document.getElementById('fs');
  shell.$wire = { close: vi.fn(), on: vi.fn(), period: 'today' };
  shell.init();

  return { shell, bound };
}

test('landing a boss\'s killing blow bumps the kills chip; someone else\'s kill does not', () => {
  const { shell, bound } = mountShell('<span class="kills-chip"><b>12</b> boss kills</span>');

  bound['boss-killed']({ killer_user_id: 2 });
  expect(document.querySelector('.kills-chip b').textContent).toBe('12');

  bound['boss-killed']({ killer_user_id: 1 });
  expect(document.querySelector('.kills-chip b').textContent).toBe('13');
  expect(document.querySelector('.kills-chip').classList.contains('bump')).toBe(true);
  shell.destroy();
});

test('a re-rendered damage figure counts to its new value (snapping under reduced motion)', async () => {
  vi.stubGlobal('matchMedia', () => ({ matches: true }));
  const { shell } = mountShell('<div class="meter-panel"><span class="dmg-wrap"><span id="dmg" data-value="1000">1K</span></span></div>');

  document.getElementById('dmg').dataset.value = '1260000';
  await new Promise(r => setTimeout(r, 0));

  expect(document.getElementById('dmg').textContent).toBe('1.26M');
  shell.destroy();
  vi.unstubAllGlobals();
});

test('focus rings follow the keyboard only: a pointer press marks mouse, Tab marks keyboard', () => {
  const { shell } = mountShell('<button id="b">x</button>');
  const root = document.getElementById('fs');

  root.dispatchEvent(new Event('pointerdown', { bubbles: true }));
  expect(root.dataset.input).toBe('mouse');
  root.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));
  expect(root.dataset.input).toBe('keyboard');
  shell.destroy();
});

test('the best combo is kept for the rest of the day, across reloads', () => {
  localStorage.clear();
  const first = clawdBuddyPanel(1);
  first.$el = document.createElement('div');
  first.init();
  first.buddy.onHit(10);
  first.buddy.onHit(10);
  first.destroy();

  const second = clawdBuddyPanel(1);
  second.$el = document.createElement('div');
  second.init();
  expect(second.bestToday).toBe(2);
  second.destroy();
});

test('the last-hit stamp ticks in the coarse wording', () => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date('2026-09-27T12:00:00Z'));
  const el = document.createElement('span');
  el.dataset.since = String(Date.parse('2026-09-27T11:59:23Z') / 1000);
  const ticker = agoTicker('coarse');
  ticker.$el = el;
  ticker.init();
  expect(ticker.label).toBe('just now');

  vi.advanceTimersByTime(90_000);
  expect(ticker.label).toBe('2m ago');

  vi.advanceTimersByTime(65 * 60_000);
  expect(ticker.label).toBe('1h ago'); // not "1h 7m ago"

  ticker.destroy();
  vi.useRealTimers();
});

const TABBED = `
  <div role="tablist">
    <button class="sheet-tab" data-tab="profile" aria-selected="true">Profile</button>
    <button class="sheet-tab" data-tab="character" aria-selected="false">Character</button>
  </div>
  <section class="sheet"><div class="tab-panel is-active" id="tab-profile"></div><div class="tab-panel" id="tab-character"></div></section>`;

test('switching tab happens at once in the page; the server only remembers it, never re-renders for it', () => {
  const { shell } = mountShell(TABBED);
  shell.$wire.selectTab = vi.fn();
  shell.$wire.dispatchSelf = vi.fn();

  document.querySelector('[data-tab="character"]').click();

  expect(document.getElementById('tab-character').classList.contains('is-active')).toBe(true);
  expect(document.getElementById('tab-profile').classList.contains('is-active')).toBe(false);
  expect(document.querySelector('[data-tab="character"]').getAttribute('aria-selected')).toBe('true');
  expect(shell.$wire.selectTab).toHaveBeenCalledWith('character');
  expect(shell.$wire.dispatchSelf).not.toHaveBeenCalled();
  shell.destroy();
});

test('closing plays the exit and keeps the loaded sheet, instead of waiting on the server to drop it', () => {
  const { shell } = mountShell(TABBED);
  const hidden = vi.fn();
  window.addEventListener('fighter-sheet-hide', hidden);

  shell.shell.close();

  expect(hidden).toHaveBeenCalledTimes(1);
  expect(shell.$wire.close).not.toHaveBeenCalled();
  window.removeEventListener('fighter-sheet-hide', hidden);
  shell.destroy();
});

test('reopening after an equip unfolds the sheet it folded on the way out', () => {
  const { shell } = mountShell(TABBED);
  document.querySelector('.sheet').classList.add('folding');

  window.dispatchEvent(new CustomEvent('open-fighter-sheet', { detail: { tab: 'profile' } }));

  expect(document.querySelector('.sheet').classList.contains('folding')).toBe(false);
  shell.destroy();
});
