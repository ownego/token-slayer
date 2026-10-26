// The Profile tab's mini stage, ported from the approved mockup's paintMini
// and crew-Clawd blocks: the equipped fighter runs on the stage in its own
// charge colours (grinding sparks off its heel), with one minion and one
// crew Clawd per subagent the viewer has working right now. With no subagent
// working the fighter runs alone: a minion is a subagent's, so none is drawn
// until one is (the Minions tab's stage is where a pool change is seen).
import { BusEvent } from '../constants.js';
import { isNewerPool, normalizeMinionPool } from '@battlefield/minion-custom.js';
import { FIGHTER_TYPES } from '../config/fighters.js';
import { chargeAnim } from '../fighter/charge-anim.js';
import { BOX } from './sheet-roster.js';
import { BY_KEY, nameOf, paint, paintCharge, standOn } from './sprite-strip.js';
import { forgetHeel, heelOf, sparkField, sparkPoint } from './stage-sparks.js';
import { registerCustomRoster } from './custom-roster.js';
import { draw, ENTRANCES, ON_CLICK, play, SEQ } from './clawd-kit.js';
import { crewCounts, crewReducer } from './sheet-ui.js';
import { minionLooks } from './minion-preview.js';

/**
 * Where minions stand around the fighter, in px from the stage centre,
 * alternating sides and stepping back — the mockup's own three spots first.
 *
 * @type {Array<{x: number, y: number}>}
 */
const MINION_SPOTS = [{ x: -72, y: 0 }, { x: 70, y: 0 }, { x: -30, y: -10 }, { x: 34, y: -10 }, { x: -104, y: -4 }, { x: 104, y: -4 }];

/**
 * The mini stage's floor line, in px above its bottom edge.
 *
 * @type {number}
 */
const FLOOR = 26;

/**
 * The fighter's body height on the mini stage, in px.
 *
 * @type {number}
 */
const BODY_PX = 84;

/**
 * The body height every minion of the pool is drawn at on the mini stage, in px: the field draws them
 * all one height whatever their sheet (config `MINION_TYPES[…].charHeight`), and so does this stage.
 *
 * @type {number}
 */
const MINION_BODY_PX = 19;

/**
 * The scale the stage draws a body `h` px tall at: a whole number from 3 to 5
 * for the small roster bodies (crisp pixels), and for a body too tall for
 * three times (the 50-61 px heroes) the exact scale to BODY_PX.
 *
 * @param {number} h The body's height in its 100 px frame.
 * @return {number}
 */
function stageScale(h) {
  const fit = BODY_PX / h;
  return fit >= 3 ? Math.min(5, Math.round(fit)) : fit;
}

/**
 * Minion spots: one per live subagent, capped at the spots we have, the first `busy` of them working (in a
 * tool call) and the rest idle, each taking the pool's looks in turn as each subagent summons one of them.
 * With no subagent there is no minion to draw, and with no looks there is nothing to draw it with.
 *
 * @param {number} total Live subagents.
 * @param {number} [busy] How many are in a tool call right now.
 * @param {Array<import('./minion-preview.js').MinionLook>} [looks] The idle strip of each look in the viewer's pool.
 * @return {Array<{i: number, x: number, y: number, kind: string, busy: boolean, look: import('./minion-preview.js').MinionLook}>}
 */
export function minionSpots(total, busy = total, looks = []) {
  if (total <= 0 || looks.length === 0) {
    return [];
  }

  return MINION_SPOTS.slice(0, Math.min(total, MINION_SPOTS.length)).map((spot, i) => ({
    i,
    ...spot,
    kind: 'm-look',
    look: looks[i % looks.length],
    busy: i < busy,
  }));
}

/**
 * The crew stat's Clawds: at most one per minion spot, the first `busy` of
 * them working, and how many more there are beyond that.
 *
 * @param {number} total Live subagents.
 * @param {number} busy How many are in a tool call.
 * @return {{icons: Array<{busy: boolean}>, more: number}}
 */
export function crewIcons(total, busy) {
  const shown = Math.min(Math.max(0, total), MINION_SPOTS.length);

  return {
    icons: Array.from({ length: shown }, (_, n) => ({ busy: n < busy })),
    more: Math.max(0, total - shown),
  };
}

/**
 * The viewer's minions already on the field that are mid tool call, read
 * off the battlefield scene so the sheet opens with the same busy split.
 *
 * @param {number} me The viewer's user id.
 * @return {Set<string>}
 */
function busyOnField(me) {
  const minions = window.__battlefield?.scene?.minions?.byUser;
  const mine = minions?.get(me) ?? minions?.get(String(me)) ?? [];

  return new Set(mine.filter(m => m.toolRing && m.assignedAgentId).map(m => String(m.assignedAgentId)));
}

/**
 * Alpine.data() component for the mini stage and its crew stat.
 *
 * @param {string} equipped The viewer's equipped fighter key.
 * @param {number} total Live subagents when the sheet rendered.
 * @param {number} me The viewer's user id, for filtering bus events.
 * @param {?object} custom The viewer's own custom fighter (`FighterSheet::$customCharacter`), equipped or not.
 *   Registered here, not left to the Character tab: this tab mounts first, and until the custom fighter
 *   is known to the sheet it cannot be shown.
 * @param {?{rev: number, defaults: Array<string>, custom: Array<object>}} minionPool The viewer's minion pool (`FighterSheet::$minionPool`);
 *   the swarm is drawn from it. Later pools replace it only when their `rev` is newer.
 * @return {object}
 */
export function miniStage(equipped, total, me, custom = null, minionPool = null) {
  registerCustomRoster(BY_KEY, BOX, custom);
  const pool = normalizeMinionPool(minionPool);

  return {
    key: BY_KEY[equipped] ? equipped : Object.keys(BY_KEY)[0],
    crew: { total, busy: new Set() },
    pool,
    looks: minionLooks(pool),
    stopSparks: null,
    unbind: null,
    tabObserver: null,
    get name() {
      return nameOf(this.key);
    },
    get counts() {
      return crewCounts(this.crew);
    },
    get minionSpots() {
      return minionSpots(this.counts.total, this.counts.busy, this.looks);
    },
    /**
     * The style properties of one minion spot: where it stands and its strip, drawn at the size that
     * gives its body the stage's minion height. Set as properties (not a style string) so a URL can
     * never break out of its value.
     *
     * @param {{x: number, y: number, look: import('./minion-preview.js').MinionLook}} spot A spot from `minionSpots`.
     * @return {Object<string, string|number>}
     */
    spotStyle(spot) {
      return {
        '--mx': `${spot.x}px`,
        '--my': `${spot.y}px`,
        '--strip': `url("${spot.look.url}")`,
        '--frames': spot.look.frames,
        '--rate': spot.look.rate,
        '--size': `${(spot.look.frame * MINION_BODY_PX / spot.look.bodyPx).toFixed(1)}px`,
      };
    },
    /**
     * What the pointer says about a minion spot.
     *
     * @param {{busy: boolean}} spot A spot from `minionSpots`.
     * @return {string}
     */
    spotTitle(spot) {
      return spot.busy ? 'working' : 'idle';
    },
    init() {
      this.crew = { total, busy: busyOnField(Number(me)) };
      this.paintFighter();
      this.stopSparks = sparkField(this.$refs.stage, (w, h) => sparkPoint({
        width: w,
        height: h,
        cxRatio: 0.5,
        floor: FLOOR,
        box: BOX[this.key],
        heel: heelOf(this.key),
        scale: parseFloat(this.$refs.fighter.style.scale) || 3,
      }), () => parseFloat(this.$refs.fighter.style.scale));
      this.drawCrew();
      // the mockup replays the crew's entrance every time the Profile tab shows
      const panel = this.$el.closest('.tab-panel');
      if (panel && typeof MutationObserver === 'function') {
        let wasActive = panel.classList.contains('is-active');
        this.tabObserver = new MutationObserver(() => {
          const active = panel.classList.contains('is-active');
          if (active && !wasActive) {
            this.drawCrew();
          }
          wasActive = active;
        });
        this.tabObserver.observe(panel, { attributes: true, attributeFilter: ['class'] });
      }
      const attach = () => {
        if (!window.__battlefield?.bindBus) {
          requestAnimationFrame(attach);

          return;
        }
        this.unbind = window.__battlefield.bindBus(window.__battlefield.bus, {
          [BusEvent.FIGHTER_AGENT_COUNT_CHANGED]: p => {
            if (Number(p.user_id) === Number(me)) {
              this.crew = crewReducer(this.crew, { type: 'count', count: p.count });
              this.drawCrew();
            }
          },
          [BusEvent.FIGHTER_AGENT_TOOL_USED]: p => {
            if (Number(p.user_id) === Number(me)) {
              this.crew = crewReducer(this.crew, { type: 'tool', agent_id: p.agent_id, busy: p.busy });
              this.drawCrew();
            }
          },
          [BusEvent.CHARACTER_CHANGED]: p => this.onCharacterBroadcast(p),
          [BusEvent.MINIONS_CHANGED]: p => this.onMinionsBroadcast(p),
        });
      };
      attach();
      // the editor in the Character tab says what the fighter is after every change
      this.onCustomEvent = event => this.onCustomChanged(event.detail ?? {});
      window.addEventListener('custom-character-changed', this.onCustomEvent);
      // the Minions tab's editor says what the pool is after every change
      this.onMinionsEvent = event => this.onPoolChanged(event.detail?.pool);
      window.addEventListener('custom-minions-changed', this.onMinionsEvent);
    },
    /**
     * Follows a pool the stage is told about: the swarm is drawn from it, unless it is older than (or as
     * old as) the one already held. Anything that is not a pool leaves the stage alone.
     *
     * @param {*} raw A pool, in the shape of the wire (`minion_pool`) or the editor's `pool`.
     * @return {boolean} Whether the stage took it.
     */
    onPoolChanged(raw) {
      const incoming = normalizeMinionPool(raw);
      if (!incoming || !isNewerPool(this.pool, incoming)) {
        return false;
      }
      this.pool = incoming;
      this.looks = minionLooks(incoming);

      return true;
    },
    /**
     * Reuses the Profile stage's bus subscription: this player's pool changed somewhere the editor did
     * not report (an admin removal, another tab), so the swarm follows and the Minions tab's editor is
     * asked to re-read. A pool the stage already holds (the editor's own change reported it first) asks
     * for nothing. Another player's change is none of this stage's business.
     *
     * @param {{user_id: number, minion_pool?: object}} payload The public `FighterMinionsChanged` broadcast.
     * @return {void}
     */
    onMinionsBroadcast(payload) {
      if (Number(payload.user_id) !== Number(me)) {
        return;
      }
      if (this.onPoolChanged(payload.minion_pool)) {
        window.Livewire?.dispatch('custom-minions-refresh');
      }
    },
    /**
     * The fighter to show for a CHARACTER_CHANGED broadcast: the custom one while the player
     * plays it, else the roster character; null for a key this sheet does not know.
     *
     * @param {{character?: string, custom_character?: ?object}} payload The broadcast.
     * @return {?string}
     */
    keyFor(payload) {
      if (payload.custom_character && BY_KEY.custom) {
        return 'custom';
      }

      return BY_KEY[payload.character] ? payload.character : null;
    },
    /**
     * Reuses the Profile stage's bus subscription to refresh the player's Character tab.
     * The broadcast has no custom fighter name, so the sheet fetches its own state.
     *
     * @param {{user_id: number, character?: string, custom_character?: ?object}} payload The public fighter change.
     * @return {void}
     */
    onCharacterBroadcast(payload) {
      if (Number(payload.user_id) !== Number(me)) {
        window.dispatchEvent(new CustomEvent('sheet-other-character-broadcast', { detail: { user_id: payload.user_id } }));
        return;
      }
      const next = this.keyFor(payload);
      if (next) {
        this.key = next;
        this.paintFighter();
      }
      window.dispatchEvent(new CustomEvent('sheet-own-character-broadcast', { detail: { user_id: payload.user_id } }));
    },
    /**
     * Follows the editor: the fighter was made, replaced, renamed, switched or removed.
     *
     * @param {{custom?: ?object, equipped?: string}} detail What `custom-character-changed` carries.
     * @return {void}
     */
    onCustomChanged({ custom = null, equipped: now } = {}) {
      forgetHeel('custom');
      registerCustomRoster(BY_KEY, BOX, custom);
      if (now && BY_KEY[now]) {
        this.key = now;
      } else if (this.key === 'custom' && !BY_KEY.custom) {
        this.key = Object.keys(BY_KEY)[0];
      }
      if (this.$refs?.fighter) {
        this.paintFighter();
      }
    },
    /**
     * Runs the equipped fighter on the stage, framed like the Character stage:
     * its charge animation, as on the field while its player works (a flier
     * hovers on its idle, a custom fighter runs).
     *
     * @return {void}
     */
    paintFighter() {
      const [, y0, , y1] = BOX[this.key];
      const ftype = FIGHTER_TYPES.find(ft => ft.key === this.key);
      paint(this.$refs.fighter, this.key, ftype ? chargeAnim(ftype) : 'walk');
      standOn(this.$refs.fighter, this.key, stageScale(y1 - y0), FLOOR);
      paintCharge(this.$el.closest('#tab-profile') || this.$el, this.key);
    },
    /**
     * Draws one crew Clawd per busy minion, each with a staggered entrance.
     *
     * @return {void}
     */
    drawCrew() {
      const host = this.$refs.crew;
      const { icons, more } = crewIcons(this.counts.total, this.counts.busy);
      host.querySelectorAll('.clawd').forEach(el => clearTimeout(el._t));
      host.innerHTML = '';
      icons.forEach(({ busy: working }, n) => {
        const el = document.createElement('span');
        el.className = working ? 'clawd busy' : 'clawd';
        el.setAttribute('role', 'button');
        el.tabIndex = 0;
        el.setAttribute('aria-label', working ? 'Busy minion' : 'Idle minion');
        el.innerHTML = '<svg viewBox="0 0 18 8" preserveAspectRatio="none" shape-rendering="crispEdges"></svg>';
        el._svg = el.querySelector('svg');
        host.appendChild(el);
        // busy minions keep looking around (Claude Code's own autoplay loop); idle ones stand still
        const rest = () => (working ? play(el, SEQ.IDLE, rest) : draw(el._svg, { pose: 'default', offset: 0 }));
        const go = () => play(el, ON_CLICK[Math.floor(Math.random() * ON_CLICK.length)], rest);
        el.addEventListener('click', go);
        el.addEventListener('keydown', e => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            go();
          }
        });
        draw(el._svg, { pose: 'default', offset: 0, x: -9 }); // off-stage until its entrance
        el._t = setTimeout(() => play(el, ENTRANCES[Math.floor(Math.random() * ENTRANCES.length)], rest), 150 + n * 220);
      });
      if (more > 0) {
        const tail = document.createElement('span');
        tail.className = 'crew-more';
        tail.textContent = `+${more}`;
        host.appendChild(tail);
      }
    },
    destroy() {
      window.removeEventListener('custom-character-changed', this.onCustomEvent);
      window.removeEventListener('custom-minions-changed', this.onMinionsEvent);
      this.tabObserver?.disconnect();
      this.stopSparks?.();
      this.unbind?.();
      this.$refs.crew?.querySelectorAll('.clawd').forEach(el => clearTimeout(el._t));
    },
  };
}
