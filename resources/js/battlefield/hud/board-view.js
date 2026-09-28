/**
 * TOP DAMAGE board: rank-change effects (pure) and the keyed DOM view
 * (rows kept by fighter id, positioned with a CSS custom property so a
 * rank change animates instead of re-rendering the whole list). Ported
 * from the mockup's `renderTop`/`rowHit`/`CROWN`/`MEDAL` and the `.top li`
 * rule blocks in battlefield-hud.css (style B always on; Pulse and
 * Overtake are both always on too — the mockup's `.rank-b`/`.fx-pulse`/
 * `.fx-over` toggles were preview-only comparisons, not real variants).
 */

import { countTo } from './index.js';
import { formatHp } from '@battlefield/format.js';
import { rankBoard } from '@battlefield/shared/board.js';

const CROWN =
  '<svg class="crown" viewBox="0 0 7 5" fill="#fbbf24" shape-rendering="crispEdges"><rect x="0" y="0" width="1" height="1"/><rect x="3" y="0" width="1" height="1"/><rect x="6" y="0" width="1" height="1"/><rect x="0" y="1" width="7" height="1"/><rect x="0" y="2" width="7" height="2"/><rect x="0" y="4" width="7" height="1" fill="#b45309"/><rect x="3" y="2" width="1" height="1" fill="#f87171"/></svg>';
const MEDAL =
  '<svg class="medal" viewBox="0 0 11 13" shape-rendering="crispEdges"><rect x="1" y="0" width="3" height="4" fill="#dc2626"/><rect x="7" y="0" width="3" height="4" fill="#2563eb"/><rect x="3" y="4" width="5" height="1" fill="var(--m2)"/><rect x="2" y="5" width="7" height="7" fill="var(--m1)"/><rect x="3" y="4" width="5" height="9" fill="var(--m1)"/><rect x="1" y="6" width="9" height="5" fill="var(--m1)"/><rect x="3" y="6" width="5" height="5" fill="var(--m2)"/><rect class="glint" x="3" y="5" width="1" height="2" fill="#fff" opacity="0"/><rect class="glint" x="4" y="5" width="1" height="1" fill="#fff" opacity="0"/></svg>';

/**
 * Diffs two consecutive `rankBoard` results into the effects the DOM view
 * plays: which rows rolled their rank digit (and which direction), which
 * rows climbed and who they passed, and whether the board got a new #1.
 * Effects are derived purely from each row's `rank`, never from the
 * `climbed`/`passedIds` fields `rankBoard` may have already stamped on
 * `next` against some other prior state.
 *
 * @param {{rows:{id:number, rank:number}[], leader:number|null}} prev
 * @param {{rows:{id:number, rank:number}[], leader:number|null}} next
 * @return {{rolls:{id:number, from:number, to:number, dir:'up'|'down'}[], climbs:{id:number, by:number}[], passed:number[], newLeader:number|null}}
 */
export function boardEffects(prev, next) {
  const prevRankById = new Map(prev.rows.map(r => [r.id, r.rank]));
  const rolls = [];
  const climbs = [];
  const passedSet = new Set();
  for (const row of next.rows) {
    const prevRank = prevRankById.get(row.id);
    if (prevRank === undefined || prevRank === row.rank) {
      continue;
    }
    const from = prevRank + 1;
    const to = row.rank + 1;
    const dir = to < from ? 'up' : 'down';
    rolls.push({ id: row.id, from, to, dir });
    if (dir === 'up') {
      climbs.push({ id: row.id, by: from - to });
      for (const other of prev.rows) {
        if (other.id !== row.id && other.rank >= row.rank && other.rank < prevRank) {
          passedSet.add(other.id);
        }
      }
    }
  }
  return { rolls, climbs, passed: [...passedSet], newLeader: next.leader !== prev.leader ? next.leader : null };
}

/**
 * Returns the board's render function: ranks the scene's per-boss totals
 * against the ranks it drew last (so a climb animates) and hands the board
 * to the view. A no-op while the scene is still booting — a hit can arrive
 * before `create()` has made `damageTotals`; the scene-ready render draws
 * the board once it exists. `reset()` forgets the last ranks (new boss).
 *
 * @param {function(): ?{damageTotals: ?Map<number, number>}} getScene
 * @param {{update: function(object): void}} view The board view.
 * @return {function(): void} With a `reset()` method.
 */
export function createBoardRenderer(getScene, view) {
  let prevRanks = new Map();
  const render = () => {
    const totals = getScene()?.damageTotals;
    if (!totals) {
      return;
    }
    const board = rankBoard(prevRanks, totals);
    prevRanks = new Map(board.rows.map(r => [r.id, r.rank]));
    view.update(board);
  };
  render.reset = () => { prevRanks = new Map(); };

  return render;
}

/**
 * Returns the board's bus `hit` handler: re-ranks, then pulses the row.
 * Both wait a microtask, because the HUD binds to the bus before the
 * Phaser scene exists and so hears a hit before the scene's own handleHit
 * has added it to `damageTotals`; ranking at once drew the board one hit
 * late and left a newcomer's first hit with no row to pop its "+N" on.
 *
 * @param {{hit: function(number, number): void, remember: function(number, ?string): void}} view The board view.
 * @param {function(): void} render Re-ranks the board from the scene's totals.
 * @return {function({user_id: number, damage: number, slack_handle: ?string}): void}
 */
export function boardHitHandler(view, render) {
  return payload => {
    const damage = Number(payload?.damage) || 0;
    view.remember?.(payload.user_id, payload.slack_handle);
    queueMicrotask(() => {
      render();
      view.hit(payload.user_id, damage);
    });
  };
}

/**
 * The keyed DOM board view: one `<li>` per fighter id, reused across
 * updates so only rank/damage actually change. Wired from `hud/index.js`
 * (or the scene) each time `rankBoard` produces a fresh board.
 *
 * @param {HTMLElement} root the `<ol>` (or a container holding one) rows mount into
 * @param {{avatar: function(number): string, name: function(number): string, color: function(number): string, isYou: function(number): boolean, handles: ?Array<{userId: number, handle: ?string}>}} lookups
 * @return {{update: function(object): void, hit: function(number, number): void, destroy: function(): void}}
 */
export function createBoardView(root, { avatar, name, color, isYou, handles: seed = [] }) {
  const ol = root.tagName === 'OL' ? root : root.querySelector('ol') ?? root;
  const rows = new Map(); // id -> { el, rank, timers }
  // id -> handle, for hitters with no fighter on the field: seeded from the
  // boot leaderboard, then kept current from each hit's own payload
  const handles = new Map(seed.filter(r => r.handle).map(r => [r.userId, r.handle]));
  const label = id => {
    const shown = name(id);

    return shown === `#${id}` && handles.has(id) ? handles.get(id) : shown;
  };
  let leader = null;

  // Style B: #1's rank digit sheds an ember roughly every 180ms.
  const emberTimer = setInterval(() => {
    const rk = ol.querySelector('li[data-rank="1"] .rk');
    if (!rk) {
      return;
    }
    const ember = document.createElement('span');
    ember.className = 'ember';
    ember.style.setProperty('--dx', (Math.random() * 12 - 6).toFixed(1) + 'px');
    ember.style.left = (30 + Math.random() * 40) + '%';
    rk.appendChild(ember);
    setTimeout(() => ember.remove(), 1150);
  }, 180);

  const flashDigit = (el, was, to, dir) => {
    const digit = el.querySelector('.rk b');
    const ghost = document.createElement('span');
    ghost.className = 'roll ' + (dir === 'up' ? 'out-up' : 'out-down');
    ghost.textContent = String(was);
    ghost.style.color = getComputedStyle(digit).color;
    digit.parentElement.appendChild(ghost);
    setTimeout(() => ghost.remove(), 340);
    digit.classList.remove('in-up', 'in-down', 'flash-up', 'flash-down');
    void digit.offsetWidth;
    digit.classList.add(dir === 'up' ? 'in-up' : 'in-down', dir === 'up' ? 'flash-up' : 'flash-down');
    clearTimeout(digit._t);
    digit._t = setTimeout(() => digit.classList.remove('flash-up', 'flash-down'), 420);
    digit.textContent = String(to);
  };

  return {
    /**
     * Renders one board snapshot: adds/removes rows, moves the survivors
     * to their new `--i` slot, and plays the rank-change/climb/leader
     * effects `boardEffects` reports between the last board and this one.
     *
     * @param {{rows:{id:number, rank:number, damage:number, share:number}[], leader:number|null, more:number}} board
     * @return {void}
     */
    update(board) {
      const prevSnapshot = { rows: [...rows.values()].map((r, _i) => ({ id: r.id, rank: r.rank })), leader };
      const keep = new Set(board.rows.map(r => r.id));
      for (const [id, row] of rows) {
        if (!keep.has(id)) {
          row.el.style.transition = 'opacity .12s ease';
          row.el.style.opacity = '0';
          setTimeout(() => row.el.remove(), 130);
          rows.delete(id);
        }
      }
      const max = Math.max(1, ...board.rows.map(r => r.damage));
      board.rows.forEach(r => {
        let row = rows.get(r.id);
        if (!row) {
          const li = document.createElement('li');
          if (isYou?.(r.id)) {
            li.className = 'you';
          }
          li.innerHTML =
            '<span class="rk">' + MEDAL + '<b>' + (r.rank + 1) + '</b></span>' +
            '<span class="av" style="background:' + color(r.id) + '">' + avatar(r.id) + '</span>' +
            '<span class="nm"><span></span><i><em style="width:0;background:' + color(r.id) + '"></em></i></span>' +
            '<span class="dm">0</span>';
          li.style.setProperty('--i', String(r.rank));
          li.style.setProperty('--fc', color(r.id));
          li.style.opacity = '0';
          li.dataset.rank = String(r.rank + 1);
          ol.appendChild(li);
          requestAnimationFrame(() => { li.style.opacity = '1'; });
          row = { id: r.id, el: li, rank: r.rank, hitT: null };
          rows.set(r.id, row);
        } else {
          row.rank = r.rank;
          row.el.style.setProperty('--i', String(r.rank));
          row.el.dataset.rank = String(r.rank + 1);
        }
        row.el.querySelector('.nm em').style.width = (100 * r.damage / max).toFixed(1) + '%';
        const nameEl = row.el.querySelector('.nm span');
        const text = label(r.id);
        if (nameEl.textContent !== text) {
          nameEl.textContent = text;
        }
        countTo(row.el.querySelector('.dm'), r.damage);
      });
      ol.style.setProperty('--n', String(board.rows.length));

      const nextSnapshot = { rows: board.rows.map(r => ({ id: r.id, rank: r.rank })), leader: board.leader };
      const fx = boardEffects(prevSnapshot, nextSnapshot);
      for (const roll of fx.rolls) {
        const row = rows.get(roll.id);
        if (row) {
          flashDigit(row.el, roll.from, roll.to, roll.dir);
        }
      }
      for (const climb of fx.climbs) {
        const row = rows.get(climb.id);
        if (!row) {
          continue;
        }
        const up = document.createElement('span');
        up.className = 'up';
        up.textContent = '▲' + climb.by;
        row.el.appendChild(up);
        setTimeout(() => up.remove(), 1600);
        const sw = document.createElement('span');
        sw.className = 'swoosh';
        row.el.appendChild(sw);
        setTimeout(() => sw.remove(), 600);
      }
      for (const id of fx.passed) {
        const row = rows.get(id);
        if (row) {
          row.el.classList.remove('passed');
          void row.el.offsetWidth;
          row.el.classList.add('passed');
        }
      }
      if (fx.newLeader !== null) {
        leader = fx.newLeader;
        ol.querySelectorAll('.crown').forEach(c => c.remove());
        const row = rows.get(fx.newLeader);
        const av = row?.el.querySelector('.av');
        if (av) {
          av.insertAdjacentHTML('beforeend', CROWN);
          av.querySelector('.crown').classList.add('drop');
        }
      }
    },

    /**
     * Keeps a hitter's handle from its hit payload, so a hitter with no
     * fighter on the field is still named on the board.
     *
     * @param {number} id
     * @param {?string} handle
     * @return {void}
     */
    remember(id, handle) {
      if (typeof handle === 'string' && handle !== '') {
        handles.set(id, handle);
      }
    },

    /**
     * Pulse: flashes the row's left edge in the fighter's colour, pops
     * the damage number and floats a `+N` above it.
     *
     * @param {number} id
     * @param {number} dmg
     * @return {void}
     */
    hit(id, dmg) {
      const row = rows.get(id);
      if (!row) {
        return;
      }
      row.el.classList.remove('hit');
      void row.el.offsetWidth;
      row.el.classList.add('hit');
      clearTimeout(row.hitT);
      row.hitT = setTimeout(() => row.el.classList.remove('hit'), 260);
      const plus = document.createElement('span');
      plus.className = 'plus';
      plus.textContent = '+' + formatHp(dmg);
      row.el.appendChild(plus);
      setTimeout(() => plus.remove(), 900);
    },

    /** @return {void} */
    destroy() {
      clearInterval(emberTimer);
      for (const row of rows.values()) {
        clearTimeout(row.hitT);
        row.el.remove();
      }
      rows.clear();
    },
  };
}
