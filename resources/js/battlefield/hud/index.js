/**
 * Alpine `battlefieldHud` component: the DOM HUD's team panel, board sheet
 * toggle and herald strip. Registered from resources/js/app.js via a
 * *static* Alpine.data import so `x-data="battlefieldHud()"` in the blade
 * always resolves, even before the lazily-imported battlefield chunk
 * (Phaser + bus.js) finishes loading. This module itself must never import
 * bus.js or anything that imports Phaser — its pure reducers below stay
 * importable in a plain Node/Vitest environment with no Phaser runtime.
 */

import { formatHp, formatTeamStat } from '../format.js';
import { heraldFor } from './feed-view.js';

/**
 * The boss name part for the herald text, uppercased, or a bare "BOSS"
 * fallback — `heraldFor` appends the `#N` itself, so this must never
 * fold the number in (unlike the DOM plate's own combined "BOSS #N"
 * fallback for when there's no name at all).
 *
 * @param {string|undefined} name
 * @return {string}
 */
function bossNamePart(name) {
  return typeof name === 'string' && name.length > 0 ? name.toUpperCase() : 'BOSS';
}

const ORD = n => (n === 1 ? '1st' : n === 2 ? '2nd' : n === 3 ? '3rd' : `${n}th`);

/**
 * Folds one hit into the team's rolling damage windows and the per-user
 * board this boss.
 *
 * @param {{today:number, month:number, allTime:number, board:Map<number,number>}} state
 * @param {{user_id:number, damage:number}} hit
 * @return {{today:number, month:number, allTime:number, board:Map<number,number>}}
 */
export function teamReducer(state, hit) {
  const board = new Map(state.board);
  board.set(hit.user_id, (board.get(hit.user_id) ?? 0) + hit.damage);
  return {
    ...state,
    today: state.today + hit.damage,
    month: state.month + hit.damage,
    allTime: state.allTime + hit.damage,
    board,
  };
}

/**
 * The viewer's own row: their damage on this boss, their share of it, and
 * their ordinal rank among everyone who has dealt damage. A user with no
 * damage yet gets zeros and a dash rank, never NaN.
 *
 * @param {{board:Map<number,number>}} state
 * @param {number} me
 * @return {{damage:number, share:number, rankLabel:string}}
 */
export function youRow(state, me) {
  const sorted = [...state.board.entries()].filter(([, d]) => d > 0).sort((a, b) => b[1] - a[1]);
  const total = sorted.reduce((s, [, d]) => s + d, 0);
  const idx = sorted.findIndex(([id]) => id === me);
  const damage = state.board.get(me) ?? 0;
  return { damage, share: total ? damage / total : 0, rankLabel: idx < 0 ? '–' : ORD(idx + 1) };
}

/**
 * Milliseconds from `now` until the next local midnight in `timeZone`,
 * used to roll the team panel's "today" (and, on the 1st, "month") window
 * over while the page stays open.
 *
 * @param {Date} now
 * @param {string} timeZone
 * @return {number}
 */
export function msUntilLocalMidnight(now, timeZone) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone,
    hour: 'numeric',
    minute: 'numeric',
    second: 'numeric',
    hour12: false,
  }).formatToParts(now);
  const get = type => Number(parts.find(p => p.type === type)?.value ?? 0);
  // Intl's 24-hour "hour" can read 24 at local midnight itself; treat that
  // as 0 so the seconds-since-midnight math below doesn't go negative.
  const hour = get('hour') % 24;
  const secondsSinceMidnight = hour * 3600 + get('minute') * 60 + get('second');
  return (24 * 3600 - secondsSinceMidnight) * 1000;
}

/**
 * Animates a number element from its last-rendered value to `to` over
 * 420ms with an ease-out cubic, formatting each frame with `fmt`.
 *
 * @param {HTMLElement} el
 * @param {number} to
 * @param {function(number): string} [fmt] defaults to the shared HP formatter
 * @param {number} [dur] ms; a live hit counts in 420, the load intro slower
 * @return {void}
 */
export function countTo(el, to, fmt = formatHp, dur = 420) {
  const from = Number(el.dataset.v || 0);
  el.dataset.v = String(to);
  if (from === to) {
    return;
  }
  const t0 = performance.now();
  cancelAnimationFrame(el._raf);
  const step = now => {
    const k = Math.min(1, (now - t0) / dur);
    const e = 1 - Math.pow(1 - k, 3);
    el.textContent = fmt(from + (to - from) * e);
    if (k < 1) {
      el._raf = requestAnimationFrame(step);
    }
  };
  el._raf = requestAnimationFrame(step);
}

/**
 * The `battlefieldHud` Alpine component factory: team damage windows, the
 * viewer's own row, the portrait board-sheet toggle, and the herald strip.
 * Registered as `Alpine.data('battlefieldHud', battlefieldHud)`.
 *
 * @return {object} the Alpine component definition
 */
export function battlefieldHud() {
  return {
    // Exposed so the blade's own x-effect="countTo($refs.x, x)" calls
    // resolve against this component's Alpine scope — a bare top-level
    // export in this module is invisible to the template, which only sees
    // this returned object's own properties/methods.
    countTo,
    // Team Damage: Today to one decimal, Month / All-time whole (see formatTeamStat)
    fmtOne: v => formatTeamStat(v, 'one'),
    fmtWhole: v => formatTeamStat(v, 'whole'),
    today: 0,
    month: 0,
    allTime: 0,
    board: new Map(),
    currentUserId: null,
    // Phones: team + board live in the stats sheet (`.bf-stats`), collapsed
    // to a peek of the board until its handle is tapped (see the phone
    // blocks at the end of battlefield-hud.css).
    boardOpen: false,
    // A big-event strip shown under the boss plate ({text, kind}); wiring
    // to real events lands with the feed in a later task.
    herald: null,
    _off: null,
    _midnightTimer: null,
    _heraldTimer: null,
    _zonesTimer: null,

    /**
     * Counts the team's numbers up from zero for the load intro.
     *
     * @return {void}
     */
    introCount() {
      const refs = { today: [this.today, this.fmtOne], month: [this.month, this.fmtWhole], allTime: [this.allTime, this.fmtWhole] };
      Object.entries(refs).forEach(([ref, [value, fmt]]) => {
        const el = this.$refs[ref];
        if (el) {
          el.dataset.v = '0';
          countTo(el, value, fmt, 1200);
        }
      });
      const me = this.$refs.meNum;
      if (me) {
        const value = Number(me.dataset.v || 0);
        me.dataset.v = '0';
        countTo(me, value, formatHp, 1200);
      }
    },

    init() {
      const mount = document.getElementById('battlefield-mount');
      if (mount) {
        try {
          const state = JSON.parse(mount.dataset.battlefieldState);
          const g = state.globalDamage || {};
          this.today = g.daily || 0;
          this.month = g.monthly || 0;
          this.allTime = g.allTime || 0;
          this.board = new Map((state.leaderboard || []).map(row => [row.userId, row.damage]));
          this.currentUserId = state.currentUserId ?? null;
        } catch (e) {
          // leave counters/board at their zero defaults if state is missing/malformed
        }
      }
      this._armMidnightRollover();
      this._zonesTimer = setInterval(() => this._pushZones(), 500);
      // the load intro (index.js reveals the HUD once the scene exists): count
      // every number up from zero again, slower than a live hit
      this.$el.addEventListener('bf-hud-intro', () => this.introCount());
      const tryWire = () => {
        const bf = window.__battlefield;
        if (!bf?.bus) {
          setTimeout(tryWire, 50);
          return;
        }
        this._off = bf.bindBus(bf.bus, {
          hit: p => this.onHit(p),
          'boss-spawned': p => this.onSpawn(p),
          'boss-killed': p => this.onKill(p),
        });
      };
      tryWire();
    },

    // Alpine calls destroy() on teardown. `bus` (bus.js) outlives every
    // game reboot, so a handler bound to it must be removed explicitly.
    destroy() {
      this._off?.();
      clearTimeout(this._midnightTimer);
      clearTimeout(this._heraldTimer);
      clearInterval(this._zonesTimer);
    },

    /**
     * Reports the HUD panels' own rects to window.__battlefield.setHudZones
     * so the fighter-spacing solver keeps avoiding wherever they currently
     * sit — the feed is excluded (its lines are pointer-events:none, so
     * they never obstruct a click or a fighter).
     *
     * @return {void}
     */
    _pushZones() {
      const setHudZones = window.__battlefield?.setHudZones;
      if (!setHudZones) {
        return;
      }
      // .bf-stats is the phone stats sheet (no box on a desktop); a collapsed
      // sheet hides .bf-team — zero-size rects are dropped
      const rects = ['.bf-nav', '.bf-team', '.bf-plate', '.bf-board', '.bf-stats', '.bf-herald']
        .map(sel => document.querySelector(sel)?.getBoundingClientRect())
        .filter(r => r && r.width > 0 && r.height > 0);
      setHudZones(rects);
    },

    /**
     * Shows a herald line for 4.5s, matching the mockup's own `herald()`.
     *
     * @param {{kind:'kill'|'spawn', text:string}} entry
     * @return {void}
     */
    _showHerald(entry) {
      this.herald = entry;
      clearTimeout(this._heraldTimer);
      this._heraldTimer = setTimeout(() => { this.herald = null; }, 4500);
    },

    /**
     * Folds a hit into the team totals and flashes them, as the mockup's
     * `bumpTeam` does.
     *
     * @param {{user_id: number, damage: number}} payload
     * @return {void}
     */
    onHit(payload) {
      const state = teamReducer(
        { today: this.today, month: this.month, allTime: this.allTime, board: this.board },
        { user_id: payload.user_id, damage: Number(payload?.damage) || 0 },
      );
      this.today = state.today;
      this.month = state.month;
      this.allTime = state.allTime;
      this.board = state.board;
      this.$el.querySelectorAll('.bf-team .t-stat').forEach(el => {
        el.classList.remove('flash');
        void el.offsetWidth;
        el.classList.add('flash');
      });
    },

    // The board resets per new boss (each boss tracks its own "on this
    // boss" damage); the rolling team windows above are untouched. Also
    // announces the new boss in the herald strip (fighter-joined/
    // fighter-agent-count-changed feed lines are a separate DOM controller,
    // hud/feed-view.js's createFeedView, wired in index.js's bootBattlefield).
    onSpawn(payload) {
      this.board = new Map();
      this._showHerald(heraldFor({ type: 'boss-spawned', boss: bossNamePart(payload?.boss_name), number: payload?.boss_number }));
    },
    onKill(payload) {
      this._showHerald(heraldFor({
        type: 'boss-killed',
        killer: payload?.killer_slack_handle ?? 'Someone',
        boss: bossNamePart(payload?.boss_name),
        number: payload?.boss_number,
      }));
    },

    toggleBoard() {
      this.boardOpen = !this.boardOpen;
      // .open changes the phone stats sheet's footprint (collapsed peek vs.
      // team + full board) — push the new rects right away rather than
      // waiting for the next 500ms tick.
      this._pushZones();
    },

    get you() {
      return youRow({ board: this.board }, this.currentUserId);
    },

    // Schedules the "today"/"month" rollover at the next local midnight
    // (Asia/Ho_Chi_Minh) and re-arms itself for the following day.
    _armMidnightRollover() {
      const ms = msUntilLocalMidnight(new Date(), 'Asia/Ho_Chi_Minh');
      this._midnightTimer = setTimeout(() => {
        const now = new Date();
        this.today = 0;
        // The 1st of the month: this local midnight is also the month's.
        const isFirstOfMonth = new Intl.DateTimeFormat('en-US', {
          timeZone: 'Asia/Ho_Chi_Minh',
          day: 'numeric',
        }).format(now) === '1';
        if (isFirstOfMonth) {
          this.month = 0;
        }
        this._armMidnightRollover();
      }, ms);
    },
  };
}
