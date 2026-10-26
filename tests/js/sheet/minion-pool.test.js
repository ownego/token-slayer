// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, test, vi } from 'vitest';
import {
  SAVE_FOLD_DELAY_MS,
  choicePayload,
  isUnsaved,
  minionPool,
  nothingTicked,
  pruned,
  tickedOf,
  toggled,
} from '@battlefield/sheet/minion-pool.js';

const saved = { 'default:demon-a': true, 'default:smw-bowser': false, 'slot:1': true, 'slot:2': true };
const looks = {
  'default:demon-a': { spec: { kind: 'default', key: 'demon-a' }, version: 'demon-a', preview: false, name: 'Demon', slot: null, hash: null },
  'default:smw-bowser': { spec: { kind: 'default', key: 'smw-bowser' }, version: 'smw-bowser', preview: false, name: 'Bowser', slot: null, hash: null },
  'slot:1': { spec: { kind: 'custom' }, version: 'aaa111', preview: false, name: 'Minion 1', slot: 1, hash: 'aaa111' },
  'slot:2': { spec: { kind: 'custom' }, version: 'bbb222', preview: false, name: 'Minion 2', slot: 2, hash: 'bbb222' },
  'slot:3': { spec: null, version: null, preview: false, name: null, slot: 3, hash: null },
};

describe('the choice', () => {
  test('a look the player has not toggled is ticked as saved', () => {
    expect(tickedOf(saved, {}, 'default:demon-a')).toBe(true);
    expect(tickedOf(saved, {}, 'default:smw-bowser')).toBe(false);
    expect(tickedOf(saved, {}, 'slot:3')).toBe(false);
  });

  test('toggling flips the look and remembers the hash it was toggled on', () => {
    const pending = toggled(saved, {}, looks, 'slot:1');

    expect(pending).toEqual({ 'slot:1': { ticked: false, hash: 'aaa111' } });
    expect(tickedOf(saved, pending, 'slot:1')).toBe(false);
  });

  test('toggling a look back to what is saved leaves nothing to save', () => {
    const once = toggled(saved, {}, looks, 'default:demon-a');
    const twice = toggled(saved, once, looks, 'default:demon-a');

    expect(twice).toEqual({});
    expect(isUnsaved(saved, twice)).toBe(false);
    expect(isUnsaved(saved, once)).toBe(true);
  });

  test('a look that cannot be ticked is not toggled', () => {
    expect(toggled(saved, {}, looks, 'slot:3')).toEqual({});
    expect(toggled(saved, {}, looks, 'nonsense')).toEqual({});
  });

  test('pruning drops what is gone, what was replaced and what now equals the stored choice', () => {
    const pending = {
      'default:demon-a': { ticked: false, hash: null },
      'slot:1': { ticked: false, hash: 'aaa111' },
      'slot:2': { ticked: false, hash: 'bbb222' },
      'slot:9': { ticked: false, hash: 'ccc333' },
    };
    const nextSaved = { ...saved, 'default:demon-a': false };
    const nextLooks = { ...looks, 'slot:2': { ...looks['slot:2'], hash: 'fff999', version: 'fff999' } };

    expect(pruned(nextSaved, pending, nextLooks)).toEqual({ 'slot:1': { ticked: false, hash: 'aaa111' } });
  });

  test('the payload names the default keys and slots left out, and the hash of every minion drawn', () => {
    const pending = { 'default:demon-a': { ticked: false, hash: null }, 'slot:2': { ticked: false, hash: 'bbb222' } };

    expect(choicePayload(saved, pending, looks)).toEqual({
      hashes: { 1: 'aaa111', 2: 'bbb222' },
      defaultsOff: ['demon-a', 'smw-bowser'],
      slotsOff: [2],
    });
  });

  test('nothing ticked only when every look that can be ticked is out', () => {
    const allOut = Object.fromEntries(Object.keys(saved).map(look => [look, { ticked: false, hash: looks[look].hash }]));

    expect(nothingTicked(saved, allOut)).toBe(true);
    expect(nothingTicked(saved, {})).toBe(false);
    expect(nothingTicked({}, {})).toBe(false);
  });
});

describe('the minionPool component', () => {
  let wire;
  let watchers;
  let host;

  /**
   * Starts the component the way Alpine does, with a fake `$wire` and element.
   *
   * @param {object} [initial] The component's argument.
   * @return {object}
   */
  function started(initial = { selected: 'default:demon-a' }) {
    const pool = minionPool(initial);
    pool.$wire = wire;
    pool.$el = host;
    pool.$nextTick = fn => fn();
    pool.init();

    return pool;
  }

  beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
    watchers = {};
    wire = {
      board: { saved, looks },
      selected: 'default:demon-a',
      replacing: null,
      confirming: null,
      $set: vi.fn(),
      $watch: vi.fn((name, fn) => { watchers[name] = fn; }),
      select: vi.fn(),
      save: vi.fn(async () => true),
    };
    document.body.innerHTML = '<div class="fs"><div class="cce cme"><div class="cme-body"><button id="cme-save-x"></button></div></div></div>';
    host = document.querySelector('.cme-body');
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    delete window.__battlefield;
  });

  test('a pick changes the stage at once and tells the server only with the next request', () => {
    const pool = started();

    pool.pick('slot:1');

    expect(pool.sel).toBe('slot:1');
    expect(wire.$set).toHaveBeenCalledWith('selected', 'slot:1', false);
    expect(wire.select).not.toHaveBeenCalled();
  });

  test('a pick away from an open Replace or confirm asks the server to close it', () => {
    wire.replacing = 1;
    const pool = started();

    pool.pick('default:demon-a');

    expect(pool.sel).toBe('default:demon-a');
    expect(wire.select).toHaveBeenCalledWith('default:demon-a');
  });

  test('a cell the server moved the stage to is followed', () => {
    const pool = started();

    watchers.selected('slot:2');

    expect(pool.sel).toBe('slot:2');
  });

  test('ticks change only in the browser until Save, and Reset drops them', () => {
    const pool = started();

    pool.toggle('default:demon-a');

    expect(pool.unsaved).toBe(true);
    expect(pool.inPool('default:demon-a')).toBe(false);
    expect(pool.isChanged('default:demon-a')).toBe(true);
    expect(wire.save).not.toHaveBeenCalled();

    pool.reset();

    expect(pool.unsaved).toBe(false);
  });

  test('a new board from the server drops the ticks it made moot', () => {
    const pool = started();
    pool.toggle('slot:2');

    wire.board = { saved, looks: { ...looks, 'slot:2': { ...looks['slot:2'], hash: 'new000' } } };
    watchers.board();

    expect(pool.unsaved).toBe(false);
  });

  test('Save sends the choice once, clears it when written and keeps it when refused', async () => {
    const pool = started();
    pool.toggle('default:demon-a');

    wire.save.mockResolvedValueOnce(false);
    await pool.saveChoice();
    expect(wire.save).toHaveBeenCalledWith({ 1: 'aaa111', 2: 'bbb222' }, ['demon-a', 'smw-bowser'], []);
    expect(pool.unsaved).toBe(true);
    expect(pool.saving).toBe(false);

    await pool.saveChoice();
    expect(pool.unsaved).toBe(false);
  });

  test('a second Save while one is in flight sends nothing', async () => {
    const pool = started();
    pool.toggle('default:demon-a');

    const first = pool.saveChoice();
    pool.saveChoice();
    await first;

    expect(wire.save).toHaveBeenCalledTimes(1);
  });

  test('a written Save folds the sheet onto the field when the player has minions out', async () => {
    window.__battlefield = { scene: { currentUserId: 7, minions: { byUser: new Map([[7, [{}]]]) } } };
    const folded = vi.fn();
    document.addEventListener('fighter-equipped', folded);
    const pool = started();
    pool.toggle('default:demon-a');

    await pool.saveChoice();
    expect(folded).not.toHaveBeenCalled();
    vi.advanceTimersByTime(SAVE_FOLD_DELAY_MS);

    expect(folded).toHaveBeenCalledTimes(1);
    document.removeEventListener('fighter-equipped', folded);
  });

  test('with no minions out the sheet stays and says the next subagents summon from the pool', async () => {
    window.__battlefield = { scene: { currentUserId: 7, minions: { byUser: new Map() } } };
    const folded = vi.fn();
    const toasts = [];
    document.addEventListener('fighter-equipped', folded);
    window.addEventListener('sheet-toast', event => toasts.push(event.detail.message));
    const pool = started();
    pool.toggle('default:demon-a');

    await pool.saveChoice();
    vi.advanceTimersByTime(SAVE_FOLD_DELAY_MS * 2);

    expect(folded).not.toHaveBeenCalled();
    expect(toasts).toContain('Pool saved. Your next subagents summon from it.');
    document.removeEventListener('fighter-equipped', folded);
  });

  test('another tab changing the pool drops the unsaved choice and says so', () => {
    const toasts = [];
    window.addEventListener('sheet-toast', event => toasts.push(event.detail.message));
    const pool = started();
    pool.toggle('default:demon-a');

    window.dispatchEvent(new CustomEvent('minion-pool-reset'));

    expect(pool.unsaved).toBe(false);
    expect(toasts).toContain('Your minions changed in another tab. Check the pool, then save again.');
    pool.destroy();
  });

  test('the stage shows only for a cell with something to draw', () => {
    const pool = started();

    expect(pool.hasStage).toBe(true);
    pool.pick('slot:3');
    expect(pool.hasStage).toBe(false);
  });

  test('the pool cells pop in each time the pane comes into view, unless the player asked for less motion', () => {
    host.insertAdjacentHTML('beforeend', '<ul><li class="pool-cell"></li><li class="pool-cell"></li></ul>');
    const pool = started();

    pool.enter();
    expect([...host.querySelectorAll('.pool-cell')].every(cell => cell.classList.contains('enter'))).toBe(true);
    host.querySelector('.pool-cell').dispatchEvent(new Event('animationend'));
    expect(host.querySelector('.pool-cell').classList.contains('enter')).toBe(false);

    vi.stubGlobal('matchMedia', () => ({ matches: true }));
    host.querySelectorAll('.pool-cell').forEach(cell => cell.classList.remove('enter'));
    pool.enter();
    expect(host.querySelector('.pool-cell.enter')).toBeNull();
  });
});
