// The Profile tab's mini stage, ported from the approved mockup's paintMini
// and crew-Clawd blocks: the equipped fighter runs on the stage in its own
// charge colours (grinding sparks off its heel), with one minion and one
// crew Clawd per subagent the viewer has working right now.
import { BusEvent } from '../constants.js';
import { BOX } from './sheet-roster.js';
import { BY_KEY, paint, paintCharge, pretty, standOn } from './sprite-strip.js';
import { heelOf, sparkField, sparkPoint } from './stage-sparks.js';
import { draw, ENTRANCES, ON_CLICK, play, SEQ } from './clawd-kit.js';
import { crewCounts, crewReducer } from './sheet-ui.js';

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
 * first `busy` of them working (in a tool call) and the rest idle.
 *
 * @param {number} total Live subagents.
 * @param {number} [busy] How many are in a tool call right now.
 * @return {Array<{i: number, x: number, y: number, kind: string, busy: boolean}>}
 */
export function minionSpots(total, busy = total) {
  return MINION_SPOTS.slice(0, Math.max(0, total)).map((s, i) => ({ i, ...s, kind: i % 2 ? 'm-demon' : 'm-goomba', busy: i < busy }));
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
 * @return {object}
 */
export function miniStage(equipped, total, me) {
  return {
    key: BY_KEY[equipped] ? equipped : Object.keys(BY_KEY)[0],
    crew: { total, busy: new Set() },
    stopSparks: null,
    unbind: null,
    tabObserver: null,
    get name() {
      return pretty(this.key);
    },
    get counts() {
      return crewCounts(this.crew);
    },
    get minionSpots() {
      return minionSpots(this.counts.total, this.counts.busy);
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
          [BusEvent.CHARACTER_CHANGED]: p => {
            if (Number(p.user_id) === Number(me) && BY_KEY[p.character]) {
              this.key = p.character;
              this.paintFighter();
            }
          },
        });
      };
      attach();
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
      this.tabObserver?.disconnect();
      this.stopSparks?.();
      this.unbind?.();
      this.$refs.crew?.querySelectorAll('.clawd').forEach(el => clearTimeout(el._t));
    },
  };
}
