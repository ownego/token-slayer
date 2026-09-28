/**
 * Boss HP plate: segmented HP bar (10% marks that crack off as the boss
 * takes damage), a lagging "ghost" bar showing the last big drop, 50%/10%
 * visual states, and a shake on big hits. Ported from the mockup's
 * `renderHp`/`crackSegment`/`shakePlate` — the shake bug fixed there
 * (relative transform + `composite: 'add'`, never `translate: -50%`,
 * which drifted once the plate moved into the grid) carries over here.
 */

import { formatHp } from '../format.js';

/**
 * The 10%-segment indices (0..9, top segment first) crossed downward
 * between two HP readings — these are the segments that crack.
 *
 * @param {number} prevHp
 * @param {number} nextHp
 * @param {number} maxHp
 * @return {number[]}
 */
export function crackedSegments(prevHp, nextHp, maxHp) {
  const before = Math.ceil((prevHp / maxHp) * 10);
  const after = Math.ceil((nextHp / maxHp) * 10);
  const out = [];
  for (let s = before - 1; s >= after; s--) {
    out.push(s);
  }
  return out;
}

/**
 * The plate's visual state at a given HP: 'enraged' at 50% or below,
 * 'finish' inside (0%, 10%] specifically (0 itself stays 'enraged' — the
 * boss is dead, not "about to die"), 'normal' otherwise.
 *
 * @param {number} hp
 * @param {number} max
 * @return {'normal'|'enraged'|'finish'}
 */
export function plateState(hp, max) {
  const p = hp / max;
  if (p > 0 && p <= 0.1) {
    return 'finish';
  }
  return p <= 0.5 ? 'enraged' : 'normal';
}

/**
 * Shakes the plate 3px on a big hit, using a *relative* transform
 * (`composite: 'add'`) layered on top of whatever transform the plate
 * already has (its grid position) — a shake driven by an absolute
 * `translate(-50%, ...)` fights the plate's own centring once it moved
 * into the HUD grid, which is the bug this fixes.
 *
 * @param {HTMLElement} el
 * @param {number} [power]
 * @return {void}
 */
function shakePlate(el, power = 3) {
  el.animate(
    [
      { transform: 'translate(0, 0)' },
      { transform: `translate(${-power}px, 1px)` },
      { transform: `translate(${power}px, -1px)` },
      { transform: 'translate(0, 0)' },
    ],
    { duration: 160, composite: 'add' },
  );
}

/**
 * How many chips a hit knocks off the HP bar's live edge: a couple for a
 * small hit, more as it grows by order of magnitude, never more than 8.
 * Only a 10% crack spawns the big 12-shard burst, and on a real boss that
 * happens once every few hundred hits, so without these a hit showed
 * nothing on the bar at all.
 *
 * @param {number} damage
 * @return {number}
 */
export function hitChips(damage) {
  if (!(damage > 0)) {
    return 0;
  }

  return Math.max(2, Math.min(8, Math.round(Math.log10(damage)) - 1));
}

/**
 * Throws one pixel shard from (`x`, `y`) inside `host`: up and out, then
 * falling as it fades — the mockup's crack shard motion.
 *
 * @param {HTMLElement} host The shard's positioned offset parent.
 * @param {number} x
 * @param {number} y
 * @param {number} spread Horizontal throw, px.
 * @param {number} rise Upward throw, px.
 * @return {void}
 */
function throwShard(host, x, y, spread, rise) {
  const s = document.createElement('span');
  s.className = 'shard';
  s.style.left = x + 'px';
  s.style.top = y + 'px';
  s.style.background = Math.random() < 0.3 ? '#fde68a' : '#fb7185';
  host.appendChild(s);
  const dx = (Math.random() - 0.5) * spread;
  const up = -rise * (0.6 + Math.random() * 0.8);
  s.animate(
    [
      { transform: 'translate(0,0) rotate(0)', opacity: 1 },
      { transform: `translate(${dx * 0.5}px,${up}px) rotate(90deg)`, opacity: 1, offset: 0.35 },
      { transform: `translate(${dx}px,${rise * 1.6 + Math.random() * 40}px) rotate(200deg)`, opacity: 0 },
    ],
    { duration: 750 + Math.random() * 300, easing: 'cubic-bezier(.3,.1,.6,1)' },
  ).onfinish = () => s.remove();
}

/**
 * Spawns 12 small pixel shards flying off the cracked segment.
 *
 * @param {HTMLElement} bar the `.hpbar` element
 * @param {HTMLElement} host the shard's positioned offset parent
 * @param {number} seg segment index 0..9
 * @return {void}
 */
function spawnShards(bar, host, seg) {
  const r = bar.getBoundingClientRect();
  const hr = host.getBoundingClientRect();
  for (let k = 0; k < 12; k++) {
    throwShard(host, r.left - hr.left + r.width * (seg / 10 + Math.random() / 10), r.top - hr.top + Math.random() * r.height, 70, 35);
  }
}

/**
 * Creates the boss plate DOM controller. `root` is the `.bf-plate` section
 * from `hud-plate.blade.php`.
 *
 * @param {HTMLElement} root
 * @return {{set: function(number, number): void, spawn: function(string|undefined, number, number): void, intro: function(number=): void, hit: function(number): void}}
 */
export function createBossPlate(root) {
  const nameEl = root.querySelector('.bp-name span');
  const numEl = root.querySelector('.bp-num');
  const fill = root.querySelector('.fill');
  const lag = root.querySelector('.lag');
  const bar = root.querySelector('.hpbar');
  const hpText = root.querySelector('.hp-t');
  let hp = 0;
  let max = 1;
  let lastSeg = 10;

  const render = (instant) => {
    const p = max ? hp / max : 0;
    const pct = (p * 100).toFixed(2) + '%';
    const seg = Math.ceil(p * 10);
    if (!instant && seg < lastSeg) {
      for (let s = lastSeg - 1; s >= seg; s--) {
        bar.style.setProperty('--seg', s * 10 + '%');
        bar.classList.remove('crack');
        void bar.offsetWidth;
        bar.classList.add('crack');
        spawnShards(bar, root, s);
      }
    }
    // an instant render (spawn, the load intro's last frame) draws the
    // current reading as-is: its segments are already gone, not to crack again
    lastSeg = seg;
    if (instant) {
      lag.style.transition = fill.style.transition = 'none';
    }
    fill.style.width = pct;
    lag.style.width = pct;
    if (instant) {
      void fill.offsetWidth;
      lag.style.transition = fill.style.transition = '';
    }
    hpText.textContent = `${formatHp(hp)} / ${formatHp(max)}`;
    const state = plateState(hp, max);
    root.classList.toggle('enraged', state === 'enraged');
    root.classList.toggle('finish', state === 'finish');
  };

  return {
    /**
     * Updates the HP reading, cracking every 10% segment crossed since
     * the last call and animating the fill/lag bars to the new width.
     *
     * @param {number} nextHp
     * @param {number} nextMax
     * @return {void}
     */
    set(nextHp, nextMax) {
      hp = Math.max(0, nextHp);
      max = nextMax || 1;
      render(false);
    },

    /**
     * A new boss: sets the plate's name/number and jumps the bar
     * instantly (no crack animation, no lag ghost from the last boss's
     * death) to `currentHp` — full HP for a real spawn (the default), or
     * the boot payload's actual reading when this is the initial render
     * of a boss already mid-fight.
     *
     * @param {string|undefined} name
     * @param {number} number
     * @param {number} maxHp
     * @param {number} [currentHp] defaults to `maxHp` (a fresh spawn)
     * @return {void}
     */
    spawn(name, number, maxHp, currentHp = maxHp) {
      nameEl.textContent = typeof name === 'string' && name.length > 0 ? name.toUpperCase() : `BOSS #${number}`;
      numEl.textContent = `#${number}`;
      max = maxHp || 1;
      hp = Math.max(0, currentHp);
      lastSeg = Math.ceil(hp / max * 10);
      render(true);
    },

    /**
     * The load intro: the bar fills up from empty to the current HP over
     * `duration` ms (ease-out), the HP number counting alongside it.
     *
     * @param {number} [duration]
     * @return {void}
     */
    intro(duration = 1100) {
      const target = max ? hp / max : 0;
      lag.style.transition = fill.style.transition = 'none';
      fill.style.width = lag.style.width = '0%';
      const t0 = performance.now();
      const step = now => {
        const k = Math.min(1, (now - t0) / duration);
        const e = 1 - Math.pow(1 - k, 3);
        if (k < 1) {
          fill.style.width = lag.style.width = `${(target * e * 100).toFixed(2)}%`;
          hpText.textContent = `${formatHp(hp * e)} / ${formatHp(max)}`;
          requestAnimationFrame(step);
        } else {
          render(true);
        }
      };
      requestAnimationFrame(step);
    },

    /**
     * Shakes the plate on a big hit and chips a few shards off the bar's
     * live edge (`hitChips`).
     *
     * @param {number} damage
     * @return {void}
     */
    hit(damage) {
      if (damage > 3500) {
        shakePlate(root);
      }
      const r = bar.getBoundingClientRect();
      const hr = root.getBoundingClientRect();
      const edge = r.left - hr.left + r.width * (max ? hp / max : 0);
      for (let k = hitChips(damage); k > 0; k--) {
        throwShard(root, edge, r.top - hr.top + Math.random() * r.height, 36, 18);
      }
    },
  };
}
