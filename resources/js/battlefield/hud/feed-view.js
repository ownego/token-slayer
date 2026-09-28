/**
 * Activity feed (grouped bursts, capped to 3 lines + a hover history) and the
 * herald strip (kills/spawns — big events that don't belong in the scrolling
 * feed). Ported from the mockup's `feed`/`paintLine`/`layoutFeed`/
 * `renderHist`/`herald`.
 */

import { createFeed } from '../shared/feed-merge.js';

const VERB = {
  join: ['joined the fight', 'joined the fight'],
  subagent: ['started a subagent', 'started subagents'],
};
const COLOR = { join: '#4ade80', subagent: '#d77757' };
const ICON = {
  join: '<svg class="ic" viewBox="0 0 8 8" fill="#4ade80" shape-rendering="crispEdges"><rect x="3" y="0" width="2" height="8"/><rect x="0" y="3" width="8" height="2"/></svg>',
  subagent: '<svg class="ic" viewBox="0 0 18 10" preserveAspectRatio="none" fill="#d77757" shape-rendering="crispEdges"><rect x="3" y="0" width="12" height="2"/><rect x="1" y="4" width="16" height="2"/><rect x="3" y="2" width="12" height="6"/><rect x="5" y="2" width="1" height="2" fill="#000"/><rect x="12" y="2" width="1" height="2" fill="#000"/><rect x="4" y="8" width="1" height="2"/><rect x="6" y="8" width="1" height="2"/><rect x="11" y="8" width="1" height="2"/><rect x="13" y="8" width="1" height="2"/></svg>',
  boss: '<svg class="ic" viewBox="0 0 8 8" fill="#fbbf24" shape-rendering="crispEdges"><rect x="1" y="1" width="6" height="1"/><rect x="0" y="2" width="8" height="3"/><rect x="1" y="5" width="6" height="2"/><rect x="2" y="3" width="1" height="1" fill="#000"/><rect x="5" y="3" width="1" height="1" fill="#000"/></svg>',
  skull: '<svg class="ic" viewBox="0 0 8 8" fill="#f87171" shape-rendering="crispEdges"><rect x="2" y="0" width="4" height="1"/><rect x="1" y="1" width="6" height="1"/><rect x="0" y="2" width="8" height="3"/><rect x="1" y="5" width="6" height="1"/><rect x="2" y="6" width="1" height="2"/><rect x="5" y="6" width="1" height="2"/><rect x="3" y="7" width="2" height="1"/><rect x="2" y="3" width="1" height="1" fill="#000"/><rect x="5" y="3" width="1" height="1" fill="#000"/></svg>',
};

/**
 * Renders the actor list: both names spelled out for one or two actors,
 * the first two plus a `+N` count for more.
 *
 * @param {string[]} actors
 * @return {string}
 */
function who(actors) {
  return actors.length <= 2 ? actors.join(', ') : `${actors[0]}, ${actors[1]} +${actors.length - 2}`;
}

/**
 * The feed line's text: "a, b +2 joined the fight", "tungot started
 * subagents ×3" (a `×N` suffix only for a single repeating actor — several
 * actors already show their own count via `who`).
 *
 * @param {{kind:string, actors:string[], count:number}} line
 * @return {string}
 */
export function feedText(line) {
  const plural = line.actors.length > 1 || line.count > 1;
  const verb = VERB[line.kind][plural ? 1 : 0];
  const times = line.count > 1 && line.actors.length === 1 ? ` ×${line.count}` : '';
  return `${who(line.actors)} ${verb}${times}`;
}

/**
 * A kill or a new boss: routed to the herald strip, never the scrolling
 * feed — these are rare, important, and worth their own moment on screen.
 *
 * @param {{type:'boss-killed'|'boss-spawned', killer?:string, boss:string, number:number}} event
 * @return {{kind:'kill'|'spawn', text:string}}
 */
export function heraldFor(event) {
  if (event.type === 'boss-killed') {
    return { kind: 'kill', text: `${event.killer} slew ${event.boss} #${event.number}` };
  }
  return { kind: 'spawn', text: `${event.boss} #${event.number} appeared` };
}

/**
 * Creates the feed's DOM controller: grouped lines with a FLIP slide when
 * one arrives or leaves, capped to `feed`'s own `max` with the rest counted
 * as "+N earlier", and a hover history of the last 12 raw events with
 * relative times. Lines are `pointer-events: none` (see the CSS) so
 * fighters under the feed stay clickable — only the corner history handle
 * takes the pointer, via `:hover` in CSS, not JS.
 *
 * @param {HTMLElement} root the `.bf-feed` section
 * @return {{push: function(string, string): void, destroy: function(): void}}
 */
export function createFeedView(root) {
  const feed = createFeed();
  const linesEl = root.querySelector('.lines');
  const earlierEl = root.querySelector('.earlier');
  const histEl = root.querySelector('.hist');
  const els = new Map(); // line id -> element
  const fadeTimers = new Map();

  const paintLine = (line) => {
    let el = els.get(line.id);
    const fresh = !el;
    if (fresh) {
      el = document.createElement('div');
      el.className = 'ln in';
      el.style.setProperty('--c', COLOR[line.kind] ?? '#94a3b8');
      linesEl.appendChild(el);
      els.set(line.id, el);
    }
    el.innerHTML = (ICON[line.kind] ?? '') + '<span>' + feedText(line) + '</span>';
    if (!fresh) {
      el.classList.remove('bump');
      void el.offsetWidth;
      el.classList.add('bump');
    }
    clearTimeout(fadeTimers.get(line.id));
    fadeTimers.set(line.id, setTimeout(() => {
      el.classList.add('fading');
      setTimeout(() => { el.remove(); els.delete(line.id); layout(); }, 500);
    }, 7000));
  };

  const layout = () => {
    const now = Date.now();
    const { lines, earlier } = feed.visible(now);
    // FLIP: capture each surviving element's position before the DOM order
    // changes, then let it animate to its new slot instead of jumping.
    const before = new Map([...els].map(([id, el]) => [id, el.getBoundingClientRect().top]));
    const keep = new Set(lines.map(l => l.id));
    for (const [id, el] of els) {
      // A line pushed past `max` by newer arrivals is hidden, not removed —
      // it stays in `els` (and FLIP-tracked) until its own fade timer
      // (set once, at creation) removes it; `visible()` never re-includes
      // it once demoted, so it can only fade from here, never reappear.
      el.style.display = keep.has(id) ? '' : 'none';
    }
    lines.forEach(l => paintLine(l));
    for (const [id, el] of els) {
      const was = before.get(id);
      if (was === undefined) {
        continue;
      }
      const at = el.getBoundingClientRect().top;
      if (was !== at) {
        el.style.transition = 'none';
        el.style.transform = `translateY(${was - at}px)`;
        requestAnimationFrame(() => { el.style.transition = ''; el.style.transform = ''; });
      }
    }
    earlierEl.textContent = earlier > 0 ? `+${earlier} earlier` : '';
  };

  const paintHistory = () => {
    const now = Date.now();
    histEl.innerHTML = feed.history().map(h =>
      `<p>${ICON[h.kind] ?? ''}<span>${h.actor}</span><time>${Math.max(1, Math.round((now - h.at) / 1000))}s</time></p>`,
    ).join('');
  };
  const historyTimer = setInterval(paintHistory, 1000);

  return {
    /**
     * Records one raw event and re-renders the visible feed + history.
     *
     * @param {string} kind
     * @param {string} actor
     * @return {void}
     */
    push(kind, actor) {
      feed.push(kind, actor, Date.now());
      paintHistory();
      layout();
    },

    /** @return {void} */
    destroy() {
      clearInterval(historyTimer);
      for (const t of fadeTimers.values()) {
        clearTimeout(t);
      }
    },
  };
}
