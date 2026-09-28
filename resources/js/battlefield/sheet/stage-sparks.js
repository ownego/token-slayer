// Grinding sparks off a fighter's back heel, as on the battlefield — ported
// from the approved mockup's own sparkField/heelOf (a 2D canvas over the
// stage, paused while hidden).
import { BY_KEY } from './sprite-strip.js';
import { BOX, stripUrl } from './sheet-roster.js';

/**
 * The battlefield draws fighters at displaySize 44 over an 18px body.
 *
 * @type {number}
 */
const BF_SCALE = 44 / 18;

/**
 * The spark canvas's own size, in px: a box around the heel that the sparks
 * fly and fall within, instead of a canvas the size of the whole stage that
 * the browser re-uploads every frame.
 *
 * @type {{w: number, h: number}}
 */
const SPARK_BOX = { w: 260, h: 180 };

/**
 * How far above the measured heel the sparks leave from, in sprite pixels:
 * the heel pixel sits on the floor line, and sparks spawned exactly there
 * read as coming from under the feet.
 *
 * @type {number}
 */
export const HEEL_RAISE = 2;

/**
 * The on-stage point sparks leave from: the fighter's back heel, a touch
 * above the floor. The sprite is centred on `cxRatio` of the stage width
 * with its feet `floor` (+ `lift`) px above the bottom edge.
 *
 * @param {{width: number, height: number, cxRatio: number, floor: number, lift?: number, box: number[], heel: ?{x: number, y: number}, scale: number}} stage
 * @return {{x: number, y: number}}
 */
export function sparkPoint({ width, height, cxRatio, floor, lift = 0, box, heel, scale }) {
  const [x0, , x1, y1] = box;
  const hl = heel || { x: (x0 + x1) / 2 - 3, y: y1 };

  return {
    x: width * cxRatio + (hl.x - (x0 + x1) / 2) * scale,
    y: height - floor - lift - (y1 - hl.y + HEEL_RAISE) * scale,
  };
}

/**
 * Measured back-heel points per fighter key (null while measuring).
 *
 * @type {Object<string, ?{x: number, y: number}>}
 */
const HEEL = {};

/**
 * The real back heel of a fighter, read off its idle frame: the rear-most
 * inked pixel in the rows just above the feet, within the body box (so a
 * spear tip or a mount's tail doesn't count). Null until the strip loads.
 *
 * @param {string} key Fighter key.
 * @return {?{x: number, y: number}}
 */
export function heelOf(key) {
  if (HEEL[key] !== undefined) {
    return HEEL[key];
  }
  HEEL[key] = null;
  const [x0, , x1, y1] = BOX[key];
  const img = new Image();
  img.onload = () => {
    const c = document.createElement('canvas');
    c.width = 100;
    c.height = 100;
    const ctx = c.getContext('2d');
    if (!ctx) {
      return;
    }
    ctx.drawImage(img, 0, 0, 100, 100, 0, 0, 100, 100);
    const px = ctx.getImageData(0, 0, 100, 100).data;
    let best = null;
    for (let y = Math.round(y1); y >= Math.round(y1) - 3 && best === null; y--) {
      for (let xx = Math.max(0, Math.floor(x0) - 4); xx <= Math.min(99, Math.ceil(x1) + 2); xx++) {
        if (px[(y * 100 + xx) * 4 + 3] > 40) {
          best = { x: xx, y: y + 1 };
          break;
        }
      }
    }
    HEEL[key] = best || { x: (x0 + x1) / 2 - 3, y: y1 };
  };
  img.src = stripUrl(BY_KEY[key].anims.idle.file);

  return null;
}

/**
 * Runs the spark emitter over `host` until the returned stop function is
 * called. `heel(w, h, r)` gives the emit point in host px; `spriteScale()`
 * the sprite's current scale, which sizes the sparks like the battlefield's.
 *
 * @param {HTMLElement} host Stage element the canvas is appended to.
 * @param {function(number, number, number): {x: number, y: number}} heel
 * @param {function(): number} spriteScale
 * @return {function(): void} Stops the loop and removes the canvas.
 */
export function sparkField(host, heel, spriteScale) {
  const cv = document.createElement('canvas');
  cv.className = 'spark-cv';
  cv.width = SPARK_BOX.w;
  cv.height = SPARK_BOX.h;
  Object.assign(cv.style, { inset: 'auto', width: `${SPARK_BOX.w}px`, height: `${SPARK_BOX.h}px` });
  host.appendChild(cv);
  let origin = { x: 0, y: 0 };
  let pal = null;
  let palAt = 0;
  const ctx = cv.getContext('2d');
  const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  let parts = [];
  let wait = 0;
  let last = performance.now();
  let raf = null;
  let idle = null;
  const pick = a => a[Math.floor(Math.random() * a.length)];

  function frame(now) {
    const dt = Math.min(0.05, (now - last) / 1000);
    last = now;
    if (host.offsetParent === null) {
      // not on screen (sheet closed, other tab): no per-frame loop, just look again soon
      parts = [];
      idle = setTimeout(() => { last = performance.now(); raf = requestAnimationFrame(frame); }, 500);

      return;
    }
    if (ctx) {
      const w = host.clientWidth;
      const h = host.clientHeight;
      const r = (spriteScale() || BF_SCALE) / BF_SCALE;
      // the palette only changes on a fighter change: read it twice a second, not every frame
      if (!pal || now - palAt > 500) {
        const css = getComputedStyle(host);
        pal = ['--c2', '--c3', '--c3', '--c4'].map(v => css.getPropertyValue(v).trim() || '#fbbf24');
        palAt = now;
      }
      const p0 = heel(w, h, r);
      // sparks fly back and up, then fall: keep the heel toward the box's lower right
      const next = { x: Math.round(p0.x - SPARK_BOX.w * 0.7), y: Math.round(p0.y - SPARK_BOX.h * 0.6) };
      if (next.x !== origin.x || next.y !== origin.y) {
        origin = next;
        cv.style.left = `${origin.x}px`;
        cv.style.top = `${origin.y}px`;
      }
      wait -= dt;
      if (!reduced && wait <= 0) {
        // same numbers as the battlefield grind emitter, times r
        const n = 2 + Math.floor(Math.random() * 3);
        for (let k = 0; k < n; k++) {
          const a = (192 + Math.random() * 36) * Math.PI / 180;
          const sp = (120 + Math.random() * 130) * r;
          parts.push({ x: p0.x, y: p0.y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp, t: 0, max: 0.18 + Math.random() * 0.2, c: Math.random() < 0.35 ? '#ffffff' : pick(pal) });
        }
        wait = 0.05 + Math.random() * 0.11;
      }
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.clearRect(0, 0, SPARK_BOX.w, SPARK_BOX.h);
      ctx.setTransform(1, 0, 0, 1, -origin.x, -origin.y);
      ctx.globalCompositeOperation = 'lighter';
      parts = parts.filter(p => (p.t += dt) < p.max);
      for (const p of parts) {
        p.vy += 1100 * r * dt;
        p.x += p.vx * dt;
        p.y += p.vy * dt;
        const k = 1 - p.t / p.max;
        const sp = Math.hypot(p.vx, p.vy) || 1;
        const dx = p.vx / sp;
        const dy = p.vy / sp;
        // streak: 14px texture x scaleX 1.4 → 0.35, 3px x scaleY 1.2 → 0.6, hot head, fading tail
        const len = 14 * r * (0.35 + 1.05 * k);
        const wid = 3 * r * (0.6 + 0.6 * k);
        const tx = p.x - dx * len;
        const ty = p.y - dy * len;
        const g = ctx.createLinearGradient(tx, ty, p.x, p.y);
        g.addColorStop(0, 'rgba(0,0,0,0)');
        g.addColorStop(0.6, p.c);
        g.addColorStop(1, p.c);
        ctx.globalAlpha = k;
        ctx.strokeStyle = g;
        ctx.lineWidth = wid;
        ctx.lineCap = 'butt';
        ctx.beginPath();
        ctx.moveTo(tx, ty);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        ctx.fillStyle = p.c;
        ctx.fillRect(p.x - wid / 2, p.y - wid / 2, wid, wid);
      }
      ctx.globalAlpha = 1;
    }
    raf = requestAnimationFrame(frame);
  }
  raf = requestAnimationFrame(frame);

  return () => {
    cancelAnimationFrame(raf);
    clearTimeout(idle);
    cv.remove();
  };
}
