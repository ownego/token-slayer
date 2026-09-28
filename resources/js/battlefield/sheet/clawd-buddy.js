// The Profile tab's live Clawd, ported from the approved mockup's own
// "live Clawd" block: jumps per hit, a 60s combo, celebrates a boss kill,
// hops/somersaults between three zones after the pointer, crouches on press
// and jumps on release, and spins dizzy on a fast pointer shake.
import { compact } from './hourly-bars.js';
import { draw, play, SEQ } from './clawd-kit.js';

/**
 * One Clawd column in px — its body is 9 columns wide.
 *
 * @type {number}
 */
const CELL_PX = 8;

/**
 * How long a combo streak stays alive between hits.
 *
 * @type {number}
 */
const COMBO_WINDOW_MS = 60_000;

/**
 * The three spots Clawd can stand at (left edge, centre, right edge),
 * derived from the panel's own width in Clawd-body columns.
 *
 * @param {number} cols The panel's width in Clawd columns.
 * @return {number[]} Left, centre, right column offsets.
 */
export function spotsFor(cols) {
  const edge = Math.max(0, Math.round(cols / 6 - 4.5));

  return [edge, Math.round((cols - 9) / 2), cols - 9 - edge];
}

/**
 * Mounts the live Clawd into `panel` (the mockup's `.side.buddy`): uses its
 * `.buddy-stage > .clawd.big > svg` when present, or builds one.
 *
 * @param {HTMLElement} panel The buddy panel.
 * @param {{onComboChange?: function(number): void}} [options] Called with the
 *   combo on every change, including the reset to zero when the window lapses.
 * @return {{onHit: function(number): void, onKill: function(): void, destroy: function(): void, combo: number}}
 */
export function createBuddy(panel, { onComboChange } = {}) {
  let el = panel.querySelector('.clawd.big');
  if (!el) {
    const stageHost = document.createElement('div');
    stageHost.className = 'buddy-stage';
    stageHost.innerHTML = '<span class="clawd big busy" role="button" tabindex="0" aria-label="Clawd"><svg viewBox="0 0 42 8" preserveAspectRatio="none" shape-rendering="crispEdges"></svg></span>';
    panel.appendChild(stageHost);
    el = stageHost.querySelector('.clawd');
  }
  const stage = el.parentElement;
  el._svg = el.querySelector('svg');
  el._svg._base = 0;

  const timers = new Set();
  const later = (fn, ms) => {
    const t = setTimeout(() => { timers.delete(t); fn(); }, ms);
    timers.add(t);

    return t;
  };
  const listeners = [];
  const on = (target, type, fn) => {
    target.addEventListener(type, fn);
    listeners.push(() => target.removeEventListener(type, fn));
  };

  let mode = 'idle'; // idle | action | move | track
  let pos = 0;
  let hover = null;
  let walkT = null;
  let settleT = null;
  let pressed = false;
  // a release that came while Clawd was mid-hop: it jumps once it lands
  let jumpOnLanding = false;
  let celebrating = false;
  let combo = 0;
  let last = 0;
  let lapse = null;

  const frame = f => {
    clearTimeout(el._t);
    draw(el._svg, f);
  };
  const rest = () => {
    if (mode === 'idle' && !hover) {
      play(el, SEQ.IDLE.map(f => ({ ...f, x: pos })), rest);
    }
  };
  const after = () => {
    mode = 'idle';
    if (hover) {
      track();
    } else {
      rest();
    }
  };
  // an action (hit, kill, click) owns Clawd until it ends; then it goes back to following or idling
  const act = seq => {
    mode = 'action';
    clearTimeout(walkT);
    play(el, seq.map(f => ({ ...f, x: (f.x || 0) + pos })), after);
  };
  // a 3-frame arms-up flick that hands control straight back to the pointer
  const quick = () => {
    if (mode === 'action') {
      return;
    }
    mode = 'action';
    clearTimeout(walkT);
    play(el, [{ pose: 'arms-up', offset: 0, x: pos }, { pose: 'arms-up', offset: 0, x: pos }, { pose: 'default', offset: 1, x: pos }], after);
  };
  const pop = (text, cls) => {
    const p = document.createElement('span');
    p.className = `buddy-pop${cls ? ` ${cls}` : ''}`;
    p.textContent = text;
    stage.appendChild(p);
    later(() => p.remove(), 1150);
  };
  const restartClass = (node, cls) => {
    if (!node) {
      return;
    }
    node.classList.remove(cls);
    void node.offsetWidth;
    node.classList.add(cls);
  };
  const setCombo = n => {
    combo = n;
    onComboChange?.(n);
  };

  // ---- three zones across the panel; Clawd lives on the bottom row ----
  let cols = 21;
  let spots = spotsFor(cols);
  let spot = 1;
  function fit() {
    cols = Math.max(21, Math.floor(panel.clientWidth / CELL_PX));
    el.style.width = `${cols * CELL_PX}px`;
    el._svg.setAttribute('viewBox', `0 0 ${cols * 2} 8`);
    spots = spotsFor(cols);
    pos = spots[spot];
    if (mode !== 'action') {
      frame({ pose: 'default', offset: 0, x: pos });
    }
  }
  let ro = null;
  if (typeof ResizeObserver === 'function') {
    ro = new ResizeObserver(fit);
    ro.observe(panel);
  }
  const zoneOf = p => {
    const r = panel.getBoundingClientRect();

    return { col: Math.min(2, Math.max(0, Math.floor((p.x - r.left) / ((r.width || 1) / 3)))), row: p.y < r.top + r.height / 2 ? 0 : 1 };
  };
  const face = dir => (dir < 0 ? 'look-left' : dir > 0 ? 'look-right' : 'default');

  // hop along the ground, 3 columns a hop (Claude Code's skip frames: crouch, then land arms-up)
  function hopTo(target, done) {
    mode = 'move';
    clearTimeout(walkT);
    const dir = Math.sign(spots[target] - pos);
    const step = () => {
      if (pos === spots[target]) {
        spot = target;
        frame({ pose: face(dir), offset: 0, x: pos });
        mode = 'track';
        done ? done() : track();

        return;
      }
      frame({ pose: face(dir), offset: 1, x: pos });
      walkT = later(() => {
        pos += dir * Math.min(3, Math.abs(spots[target] - pos));
        frame({ pose: 'arms-up', offset: 0, x: pos });
        walkT = later(step, 60);
      }, 60);
    };
    step();
  }
  // two columns over: a somersault arc instead of three hops
  function flipTo(target, done) {
    mode = 'move';
    clearTimeout(walkT);
    const from = pos;
    const to = spots[target];
    const dir = Math.sign(to - from);
    const dx = (to - from) * CELL_PX;
    frame({ pose: 'default', offset: 1, x: from, poof: 'dot' });
    later(() => {
      frame({ pose: 'arms-up', offset: 0, x: from });
      el._svg.style.transformOrigin = `${(from + 4.5) * CELL_PX}px 60%`;
      const anim = el._svg.animate?.([
        { transform: 'translate(0,0) rotate(0deg)' },
        { transform: `translate(${dx * 0.5}px,-26px) rotate(${dir * 180}deg)`, offset: 0.5 },
        { transform: `translate(${dx}px,0) rotate(${dir * 360}deg)` },
      ], { duration: 520, easing: 'cubic-bezier(.4,0,.3,1)' });
      // finish on the animation OR a timer, whichever comes first (onfinish can stall in a hidden tab)
      let landed = false;
      const land = () => {
        if (landed) {
          return;
        }
        landed = true;
        anim?.cancel();
        el._svg.style.transform = '';
        pos = to;
        spot = target;
        frame({ pose: 'default', offset: 1, x: pos, poof: 'wave' });
        later(() => {
          frame({ pose: face(-dir), offset: 0, x: pos });
          mode = 'track';
          done?.();
        }, 90);
      };
      if (anim) {
        anim.onfinish = land;
      }
      later(land, 560);
    }, 70);
  }
  function track() {
    if (jumpOnLanding && mode !== 'move') {
      jumpOnLanding = false;
      act(SEQ.JUMP);
      pop('boing!');

      return;
    }
    if (mode === 'action' || mode === 'move' || !hover) {
      return;
    }
    mode = 'track';
    if (pressed) {
      frame({ pose: 'default', offset: 1, x: pos, poof: 'dot' });

      return;
    }
    const zone = zoneOf(hover);
    const dist = zone.col - spot;
    // eyes look toward the pointer's column, straight ahead in its own
    if (!dist) {
      const r = el.getBoundingClientRect();
      const mid = r.left + (pos + 4.5) * CELL_PX * ((r.width || 1) / (cols * CELL_PX));
      frame({ pose: Math.abs(hover.x - mid) < CELL_PX * 2 ? 'default' : face(hover.x - mid), offset: 0, x: pos });

      return;
    }
    // eyes follow at once; the move waits for the pointer to settle, so a quick sweep reads as two columns over
    frame({ pose: face(dist), offset: 0, x: pos });
    clearTimeout(settleT);
    settleT = later(() => {
      if (!hover || mode === 'move' || mode === 'action') {
        return;
      }
      const d2 = zoneOf(hover).col - spot;
      if (!d2) {
        return;
      }
      (Math.abs(d2) === 2 ? flipTo : hopTo)(zoneOf(hover).col, () => track());
    }, 120);
  }

  on(panel, 'pointerenter', e => {
    hover = { x: e.clientX, y: e.clientY };
    if (mode === 'action' && !celebrating) {
      clearTimeout(el._t);
      mode = 'idle';
    }
    track();
  });
  // shake the pointer left-right fast → dizzy spin
  let flips = [];
  let lastDir = 0;
  let lastX = null;
  on(panel, 'pointermove', e => {
    hover = { x: e.clientX, y: e.clientY };
    if (lastX !== null) {
      const dir = Math.sign(e.clientX - lastX);
      if (dir && dir !== lastDir && Math.abs(e.clientX - lastX) > 3) {
        const now = performance.now();
        flips = flips.filter(t => now - t < 700);
        flips.push(now);
        lastDir = dir;
        if (flips.length >= 5 && mode !== 'action' && mode !== 'move') {
          flips = [];
          act(SEQ.SPIN);
          pop('@_@');

          return;
        }
      }
    }
    lastX = e.clientX;
    track();
  });
  on(panel, 'pointerleave', () => {
    hover = null;
    lastX = null;
    pressed = false;
    if (mode === 'action') {
      return;
    }
    const home = () => {
      mode = 'idle';
      rest();
    };
    if (mode === 'move') {
      later(() => {
        if (!hover) {
          spot === 1 ? home() : hopTo(1, home);
        }
      }, 600);

      return;
    }
    spot === 1 ? home() : hopTo(1, home);
  });
  // press on Clawd = crouch for a jump; release = jump. The viewer wins over
  // a hit's reaction (which it cuts short) — only a boss-kill celebration
  // plays out — and a press mid-hop jumps once Clawd lands.
  on(el, 'pointerdown', e => {
    if (celebrating) {
      return;
    }
    if (mode === 'action') {
      clearTimeout(el._t);
      mode = 'track';
    }
    pressed = true;
    el.setPointerCapture?.(e.pointerId);
    track();
  });
  on(el, 'pointerup', () => {
    if (!pressed) {
      return;
    }
    pressed = false;
    if (mode === 'move') {
      jumpOnLanding = true;

      return;
    }
    act(SEQ.JUMP);
    pop('boing!');
  });
  on(el, 'keydown', e => {
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      act(Math.random() < 0.5 ? SEQ.JUMP : SEQ.LOOK);
    }
  });

  fit();
  act(SEQ.SKIP);

  return {
    get combo() {
      return combo;
    },
    /**
     * Reacts to one of the viewer's own hits: extends the combo and jumps.
     *
     * @param {number} damage The hit's damage.
     * @return {void}
     */
    onHit(damage) {
      const now = Date.now();
      setCombo(now - last <= COMBO_WINDOW_MS ? combo + 1 : 1);
      last = now;
      clearTimeout(lapse);
      lapse = later(() => setCombo(0), COMBO_WINDOW_MS);
      restartClass(panel.querySelector('.combo'), 'bump');
      restartClass(panel.querySelector('.combo-bar > span'), 'drain');
      // a hop toward the pointer or a press in progress is the viewer's: the
      // hit only pops its number, never cuts them short
      if (mode === 'move' || pressed) {
        pop(`+${compact(damage)}`);

        return;
      }
      if (hover) {
        quick();
      } else {
        act(SEQ.JUMP);
      }
      pop(`+${compact(damage)}`);
    },
    /**
     * Celebrates the viewer landing a boss's killing blow.
     *
     * @return {void}
     */
    onKill() {
      celebrating = true;
      act(SEQ.CELEBRATE);
      later(() => { celebrating = false; }, SEQ.CELEBRATE.length * 60 + 50);
      pop('Boss down!', 'kill');
    },
    /**
     * Stops every timer and listener.
     *
     * @return {void}
     */
    destroy() {
      clearTimeout(el._t);
      timers.forEach(clearTimeout);
      timers.clear();
      listeners.forEach(fn => fn());
      ro?.disconnect();
    },
  };
}
