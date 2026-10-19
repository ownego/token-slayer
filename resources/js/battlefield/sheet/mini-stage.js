// The Profile tab's mini stage, ported from the approved mockup's paintMini
// and crew-Clawd blocks: the equipped fighter runs on the stage in its own
// charge colours (grinding sparks off its heel), with one minion and one
// crew Clawd per subagent the viewer has working right now.
import { BusEvent } from '../constants.js';
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
 * Minion spots: one per live subagent, capped at the spots we have, the
 * first `busy` of them working (in a tool call) and the rest idle. With the
 * player's own minions (`looks`) every spot is a `m-custom` taking the looks
 * in turn, as each subagent summons one of the set; with none it keeps the
 * two default minions.
 *
 * @param {number} total Live subagents.
 * @param {number} [busy] How many are in a tool call right now.
 * @param {Array<{url: string, frames: number, rate: number}>} [looks] The idle strip of each minion in the player's set.
 * @return {Array<{i: number, x: number, y: number, kind: string, busy: boolean, look?: {url: string, frames: number, rate: number}}>}
 */
export function minionSpots(total, busy = total, looks = []) {
  return MINION_SPOTS.slice(0, Math.max(0, total)).map((s, i) => (looks.length
    ? { i, ...s, kind: 'm-custom', look: looks[i % looks.length], busy: i < busy }
    : { i, ...s, kind: i % 2 ? 'm-demon' : 'm-goomba', busy: i < busy }));
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
 * @param {Array<object>} minions The viewer's custom minions (`FighterSheet::$customMinions`); the swarm is drawn from them when there are any.
 * @return {object}
 */
export function miniStage(equipped, total, me, custom = null, minions = []) {
  registerCustomRoster(BY_KEY, BOX, custom);

  return {
    key: BY_KEY[equipped] ? equipped : Object.keys(BY_KEY)[0],
    crew: { total, busy: new Set() },
    looks: minionLooks(minions),
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
     * The style properties of one minion spot: where it stands and, for a custom minion, its strip.
     * Set as properties (not a style string) so a URL can never break out of its value.
     *
     * @param {{x: number, y: number, look?: {url: string, frames: number, rate: number}}} spot A spot from `minionSpots`.
     * @return {Object<string, string|number>}
     */
    spotStyle(spot) {
      const style = { '--mx': `${spot.x}px`, '--my': `${spot.y}px` };
      if (spot.look) {
        style['--strip'] = `url("${spot.look.url}")`;
        style['--frames'] = spot.look.frames;
        style['--rate'] = spot.look.rate;
      }

      return style;
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
      // the Minions tab's editor says what the set is after every change
      this.onMinionsEvent = event => this.onMinionsChanged(event.detail?.minions);
      window.addEventListener('custom-minions-changed', this.onMinionsEvent);
    },
    /**
     * Follows the editor: the set of minions changed, so the swarm is drawn from the new set (or the
     * defaults when it is empty). Anything that is not a list leaves it alone.
     *
     * @param {*} minions The set (`FighterSheet::$customMinions` shape).
     * @return {void}
     */
    onMinionsChanged(minions) {
      if (Array.isArray(minions)) {
        this.looks = minionLooks(minions);
      }
    },
    /**
     * Reuses the Profile stage's bus subscription: this player's set changed (an admin removal reaches
     * them this way), so the swarm follows and the Character tab's editor is asked to refresh. Another
     * player's change is none of this stage's business.
     *
     * @param {{user_id: number, custom_minions?: Array<object>}} payload The public `FighterMinionsChanged` broadcast.
     * @return {void}
     */
    onMinionsBroadcast(payload) {
      if (Number(payload.user_id) !== Number(me)) {
        return;
      }
      this.onMinionsChanged(payload.custom_minions);
      window.dispatchEvent(new CustomEvent('sheet-own-minions-broadcast', { detail: { user_id: payload.user_id } }));
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
     * Runs the equipped fighter on the stage, framed like the Character stage.
     *
     * @return {void}
     */
    paintFighter() {
      const [, y0, , y1] = BOX[this.key];
      paint(this.$refs.fighter, this.key, 'walk'); // runs, like a charging fighter in battle
      standOn(this.$refs.fighter, this.key, Math.max(3, Math.min(5, Math.round(BODY_PX / (y1 - y0)))), FLOOR);
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
