import Phaser from 'phaser';
import { TextureKey } from '@battlefield/constants.js';
import { ensureMoteSoftTexture } from '@battlefield/spark-texture.js';
import { moonDisplay, skyFrame, mixHex } from './sky-layer.js';
import { horizonYFor } from '@battlefield/layout.js';
import { dressingLayout } from './dressing.js';
import { BIRD_FRAMES, castlePixels, cloudBank, cloudPixels, flockLayout, hillProfile, meteorFrame, meteorPlan, mountainProfile, mountainShader, nextSkyEvent, treePixels } from './pixel-art.js';

/**
 * Deterministic linear-congruential PRNG (same constants as the mockup) so
 * the sky's star field, cloud blobs, and ridge silhouettes are stable
 * across a scene restart (orientation change) rather than re-rolling into
 * a visibly different arrangement every rotate.
 *
 * @param {number} seed
 * @return {function(): number} 0..1
 */
function makeRnd(seed) {
  let s = seed;
  return () => (s = (s * 16807) % 2147483647) / 2147483647;
}

/**
 * Parses a `#rrggbb` hex colour into the 0xRRGGBB integer Phaser tint/fill
 * setters expect.
 *
 * @param {string} hex
 * @return {number}
 */
function toInt(hex) {
  return parseInt(hex.slice(1), 16);
}

/**
 * Greyscale for each pixel-art tone (1 shadow, 2 body, 3 lit): the art is
 * drawn light and takes its colour from the per-minute tint, so a darker
 * grey reads as the same material in shadow.
 *
 * @type {Object<number, string>}
 */
const TONES = { 1: '#aeb7cc', 2: '#dde3ee', 3: '#ffffff' };

/**
 * Paints a tone grid onto a 2D context at (ox, oy), `u` px per cell.
 *
 * @param {CanvasRenderingContext2D} c
 * @param {number[][]} grid
 * @param {number} ox
 * @param {number} oy
 * @param {number} u
 * @return {void}
 */
function paintTones(c, grid, ox, oy, u) {
  grid.forEach((row, y) => row.forEach((tone, x) => {
    if (tone) {
      c.fillStyle = TONES[tone];
      c.fillRect(ox + x * u, oy + y * u, u, u);
    }
  }));
}

/**
 * Builds the living-sky layer plus its arena dressing: a real-astronomy
 * sun/moon, a colour-graded sky/ridge/floor gradient, drifting clouds, a
 * star field, an occasional shooting star, two braziers, a distant keep
 * (drawn straight into the far ridge's own texture so it takes the same
 * tint), floating dust that turns to fireflies after dusk, and an
 * occasional bird flock by day — all placed from the world box (see
 * `dressing.js`'s `dressingLayout`), never fixed coordinates, so it works
 * unchanged in landscape and portrait.
 *
 * @param {Phaser.Scene} scene
 * @param {{layout: object, sky: {lat: number, lon: number}|null|undefined}} options
 * @return {{update: function(Date, boolean=): void, tick: function(number, number): void, destroy: function(): void, horizonY: number}}
 */
export function createEnvironment(scene, { layout, sky }) {
  const W = layout.logicalWidth;
  const H = layout.logicalHeight;
  const HZ = horizonYFor(layout);
  const site = { lat: sky?.lat ?? 21.03, lon: sky?.lon ?? 105.85 };
  const rnd = makeRnd(11);

  // A scene.restart() (orientation change) re-runs this in the same Scene
  // instance at a new W/H — every canvas texture below is sized to the
  // current box, so a stale one from the previous mode must be removed
  // before it's recreated at the new size.
  const fresh = key => {
    if (scene.textures.exists(key)) {
      scene.textures.remove(key);
    }
    return key;
  };

  const env = {
    layer: scene.add.layer(),
    twinkles: [],
    clouds: [],
    lastKey: '',
    night: 0,
    day: 0,
    dusk: 0,
  };

  // Sky: a small canvas stretched over the whole horizon band (gradient +
  // a soft warm glow at dawn/dusk), redrawn only when the minute changes.
  env.skyTex = scene.textures.createCanvas(fresh('env-sky'), Math.ceil(W / 4), Math.ceil((HZ + 30) / 4));
  env.sky = scene.add.image(0, 0, 'env-sky').setOrigin(0).setDisplaySize(W, HZ + 30);

  // Stars: one static field baked once, plus a handful of independently
  // twinkling rectangles layered on top.
  const st = scene.textures.createCanvas(fresh('env-stars'), W, HZ);
  const sc = st.context;
  for (let i = 0; i < Math.round((W * HZ) / 2200); i++) {
    sc.fillStyle = 'rgba(214,228,255,' + (0.25 + rnd() * 0.6).toFixed(2) + ')';
    sc.fillRect(Math.floor(rnd() * W), Math.floor(rnd() * (HZ - 12)), rnd() < 0.12 ? 2 : 1, rnd() < 0.12 ? 2 : 1);
  }
  st.refresh();
  env.stars = scene.add.image(0, 0, 'env-stars').setOrigin(0);
  for (let i = 0; i < 12; i++) {
    env.twinkles.push({ o: scene.add.rectangle(rnd() * W, rnd() * (HZ - 20), 2, 2, 0xffffff), ph: rnd() * 6, sp: 1.5 + rnd() * 2 });
  }

  // Moon: redrawn only when today's phase moves a visible step.
  env.moonTex = scene.textures.createCanvas(fresh('env-moon'), 18, 18);
  env.moonPhaseKey = -1;
  env.moonGlow = scene.add.image(0, 0, TextureKey.SOFTGLOW).setTint(0xcfe0ff).setAlpha(0).setScale(1.3);
  env.moon = scene.add.image(0, 0, 'env-moon').setScale(Math.max(2, Math.round(W / 480))).setAlpha(0);

  // Sun: a warm glow plus a solid disc, both hidden below the horizon.
  env.sunGlow = scene.add.image(0, 0, TextureKey.SOFTGLOW).setBlendMode('ADD').setAlpha(0);
  env.sun = scene.add.circle(0, 0, Math.round(W / 60), 0xfff1b8).setAlpha(0);

  // Clouds (environment/pixel-art.js): lone cumulus of three sizes plus two
  // banks of several clouds grouped together, each drifting at its own
  // speed and drawn at a whole-number scale so the pixels stay square.
  const px = Math.max(2, Math.round(W / 640));
  const cloudShapes = [
    { w: 30, h: 12, bank: false }, { w: 44, h: 15, bank: false }, { w: 56, h: 18, bank: false },
    { w: 110, h: 24, bank: true }, { w: 150, h: 28, bank: true },
  ];
  cloudShapes.forEach((shape, c) => {
    const ct = scene.textures.createCanvas(fresh('env-cloud' + c), shape.w, shape.h);
    paintTones(ct.context, (shape.bank ? cloudBank : cloudPixels)(shape.w, shape.h, rnd), 0, 0, 1);
    ct.refresh();
    ct.setFilter(Phaser.Textures.FilterMode.NEAREST);
    const copies = shape.bank ? 1 : 2;
    for (let k = 0; k < copies; k++) {
      env.clouds.push({
        o: scene.add.image(rnd() * W, 16 + rnd() * (HZ * 0.45), 'env-cloud' + c).setScale(shape.bank ? px : px + (rnd() < 0.3 ? 1 : 0)),
        sp: (shape.bank ? 3 : 5) + rnd() * 5,
        base: 0.8 + rnd() * 0.2,
      });
    }
  });

  // Far mountains and near wooded hills (environment/pixel-art.js), drawn
  // light and tinted per minute by the current ambient light so the horizon
  // reads with depth: the mountains get lit and shadowed faces and snow on
  // their highest peaks; the hills carry pine trees on their crest.
  // Ranges are pixel art on a coarse grid (R screen px per art pixel), enlarged
  // with nearest-neighbour: drawn at 1px, the dither aliased into stripes once
  // the camera scaled it.
  const R = Math.max(2, Math.round(W / 480));
  const mountains = (key, loF, hiF) => {
    const gw = Math.ceil(W / R);
    const gh = Math.ceil(HZ / R);
    const t = scene.textures.createCanvas(fresh(key), gw, gh);
    const c = t.context;
    const lo = Math.round((HZ * loF) / R);
    const hi = Math.round((HZ * hiF) / R);
    const sky = mountainProfile(gw, lo, hi, rnd);
    // the skyline in screen px, for seating the castle on it
    env[key + 'Sky'] = Array.from({ length: W }, (_, x) => sky[Math.min(gw - 1, Math.floor(x / R))] * R);
    // shaded per peak (environment/pixel-art.js's mountainShader): lit left
    // faces and shadowed right ones split along each peak's drifting spine,
    // rock darkening toward the base, mist rising from it, ragged snow on the
    // high peaks — written in one pass
    const img = c.createImageData(gw, gh);
    const shade = mountainShader(sky, { lo, hi, base: gh });
    for (let x = 0; x < gw; x++) {
      for (let y = sky[x]; y < gh; y++) {
        const g = shade(x, y).grey;
        const i = (y * gw + x) * 4;
        img.data[i] = g;
        img.data[i + 1] = g;
        img.data[i + 2] = g;
        img.data[i + 3] = 255;
      }
    }
    c.putImageData(img, 0, 0);
    t.refresh();
    t.setFilter(Phaser.Textures.FilterMode.NEAREST);
    return scene.add.image(0, 0, key).setOrigin(0).setScale(R);
  };

  const hills = key => {
    const t = scene.textures.createCanvas(fresh(key), W, HZ);
    const c = t.context;
    const { profile, trees } = hillProfile(W, Math.round(HZ * 0.8), Math.round(HZ * 0.97), rnd);
    c.fillStyle = '#ffffff';
    profile.forEach((y, x) => c.fillRect(x, y, 1, HZ - y));
    // a mixed forest in clumps with clearings: pines, tall pines, round trees and bushes
    for (const tree of trees) {
      const art = treePixels(tree.kind, tree.h);
      paintTones(c, art, tree.x - Math.floor(art[0].length / 2), tree.y - art.length + 1, 1);
    }
    t.refresh();
    return scene.add.image(0, 0, key).setOrigin(0);
  };
  // two ranges: a hazy, taller one far behind, and the nearer range the castle stands on
  env.back = mountains('env-back', 0.36, 0.72);
  env.far = mountains('env-far', 0.52, 0.88);
  env.near = hills('env-near');

  const dressing = dressingLayout({ width: W, height: H, horizonY: HZ });

  // The distant castle on the far mountains (environment/pixel-art.js): its
  // own shaded texture, tinted with the mountains so it sits in the same
  // light; its dark window cells glow at night.
  {
    const art = castlePixels();
    const u = dressing.keep.castleUnit;
    const ct = scene.textures.createCanvas(fresh('env-castle'), art[0].length, art.length);
    paintTones(ct.context, art, 0, 0, 1);
    ct.refresh();
    ct.setFilter(Phaser.Textures.FilterMode.NEAREST);
    // stands on the mountains: its base sinks a little below the lowest
    // skyline point under its footprint, so no edge hangs over a slope
    const half = Math.ceil((art[0].length * u) / 2);
    const footprint = env['env-farSky'].slice(Math.max(0, dressing.keep.x - half), Math.min(W, dressing.keep.x + half));
    const base = Math.min(HZ, Math.max(...footprint) + 2 * u);
    env.castle = scene.add.image(dressing.keep.x, base, 'env-castle').setOrigin(0.5, 1).setScale(u);
    const left = dressing.keep.x - (art[0].length * u) / 2;
    const top = base - art.length * u;
    env.windows = [];
    art.forEach((row, y) => row.forEach((tone, x) => {
      // windows: dark cells above the gate's rows
      if (tone === 1 && y < art.length - 6) {
        env.windows.push(scene.add.rectangle(left + x * u, top + y * u, u, u, 0xffc861).setOrigin(0));
      }
    }));
    // at night each lit window throws a soft warm glow, and two torches
    // flicker either side of the gate
    env.windowGlows = env.windows.map(w => scene.add.image(w.x + u / 2, w.y + u / 2, TextureKey.SOFTGLOW)
      .setTint(0xffb347).setBlendMode('ADD').setScale(u * 0.12).setAlpha(0));
    const gateY = base - 5 * u;
    env.torches = [-3.5, 3.5].map(dx => scene.add.image(dressing.keep.x + dx * u, gateY, TextureKey.SOFTGLOW)
      .setTint(0xff8a3a).setBlendMode('ADD').setScale(u * 0.18).setAlpha(0));
  }

  // Birds: a flock of small flapping pixel birds in a loose V, now and then
  // by day.
  BIRD_FRAMES.forEach((frame, i) => {
    const bt = scene.textures.createCanvas(fresh('env-bird' + i), frame[0].length, frame.length);
    bt.context.fillStyle = '#1c2438';
    frame.forEach((row, y) => Array.from(row).forEach((ch, x) => { if (ch === 'X') { bt.context.fillRect(x, y, 1, 1); } }));
    bt.refresh();
    bt.setFilter(Phaser.Textures.FilterMode.NEAREST);
  });
  env.flock = flockLayout(6, rnd);
  env.birds = env.flock.map(() => scene.add.image(0, 0, 'env-bird0').setScale(px).setAlpha(0));

  // Floor: a neutral stone pattern, tinted by the current ambient light.
  const ft = scene.textures.createCanvas(fresh('env-floor'), W, H - HZ);
  const fc = ft.context;
  const fh = H - HZ;
  const fg = fc.createLinearGradient(0, 0, 0, fh);
  fg.addColorStop(0, '#c9cfdc');
  fg.addColorStop(1, '#8d94a6');
  fc.fillStyle = fg;
  fc.fillRect(0, 0, W, fh);
  for (let y = 6, row = 0; y < fh; row++) {
    fc.fillStyle = 'rgba(0,0,0,' + (0.05 + row * 0.006).toFixed(3) + ')';
    fc.fillRect(0, y, W, 1);
    y += 6 + row * 3;
  }
  for (let i = -12; i <= 12; i++) {
    fc.strokeStyle = 'rgba(0,0,0,0.05)';
    fc.beginPath();
    fc.moveTo(W / 2 + (i * W) / 36, 0);
    fc.lineTo(W / 2 + (i * W) / 6, fh);
    fc.stroke();
  }
  for (let i = 0; i < (W * fh) / 900; i++) {
    const x = Math.floor(rnd() * W);
    const y = 8 + Math.floor(rnd() * (fh - 8));
    fc.fillStyle = 'rgba(40,70,40,' + (0.15 + rnd() * 0.2).toFixed(2) + ')';
    fc.fillRect(x, y, 2, 1);
    fc.fillRect(x + 1, y - 1, 1, 1);
  }
  ft.refresh();
  env.floor = scene.add.image(0, HZ, 'env-floor').setOrigin(0);
  env.horizon = scene.add.rectangle(0, HZ, W, 2, 0xffffff, 0.18).setOrigin(0);

  // Braziers: flank the arena on the floor just below the horizon — a
  // graphics stand, an ADD-blended glow that brightens after dark, and a
  // small one-shot fire particle burst per flame.
  ensureMoteSoftTexture(scene);
  env.braziers = dressing.braziers.map(({ x: bx, y: by }) => {
    const u = dressing.keep.unit;
    const g = scene.add.graphics();
    g.fillStyle(0x3b3f52, 1); g.fillRect(bx - 5 * u, by - 3 * u, 10 * u, 3 * u);
    g.fillStyle(0x2a2d3c, 1); g.fillRect(bx - 4 * u, by, 8 * u, u);
    g.fillStyle(0x23252f, 1); g.fillRect(bx - u, by + u, 2 * u, 7 * u); g.fillRect(bx - 3 * u, by + 8 * u, 6 * u, u);
    const glow = scene.add.image(bx, by - 4 * u, TextureKey.SOFTGLOW).setTint(0xff9a3c).setBlendMode('ADD');
    const fire = scene.add.particles(bx, by - 3 * u, TextureKey.MOTE_SOFT, {
      lifespan: { min: 380, max: 700 }, speedY: { min: -19 * u, max: -9 * u }, speedX: { min: -6, max: 6 },
      scale: { start: u * 0.9, end: 0 }, alpha: { start: 0.95, end: 0 }, frequency: 38, quantity: 1,
      tint: [0xfff1a8, 0xffb347, 0xff6a2a], blendMode: 'ADD',
      emitZone: { type: 'random', source: new Phaser.Geom.Rectangle(-3 * u, 0, 6 * u, 2) },
    });
    return { g, glow, fire, u };
  });

  // Air: sunlit dust by day, turning to fireflies from dusk.
  env.motes = Array.from({ length: dressing.count.motes }, () => ({
    o: scene.add.rectangle(rnd() * W, dressing.moteBand.top + rnd() * (dressing.moteBand.bottom - dressing.moteBand.top) * 0.8, 2, 2, 0xfff3c4),
    ph: rnd() * 6, sx: 4 + rnd() * 8, y0: 0,
  }));
  env.motes.forEach(m => { m.y0 = m.o.y; });

  // Vignette: a static radial darken at the edges, baked once.
  const vt = scene.textures.createCanvas(fresh('env-vig'), W, H);
  const vc = vt.context;
  const vg = vc.createRadialGradient(W / 2, H / 2, Math.min(W, H) * 0.35, W / 2, H / 2, Math.max(W, H) * 0.72);
  vg.addColorStop(0, 'rgba(0,0,0,0)');
  vg.addColorStop(1, 'rgba(0,0,0,0.5)');
  vc.fillStyle = vg;
  vc.fillRect(0, 0, W, H);
  vt.refresh();
  env.vig = scene.add.image(0, 0, 'env-vig').setOrigin(0);

  // Shooting star: a tapered trail — hair-thin and faint at the tail, a hot
  // white core with a blue fringe at the head — plus a small glowing head.
  {
    const mt = scene.textures.createCanvas(fresh('env-meteor'), 256, 9);
    const mc = mt.context;
    for (let x = 0; x < 256; x++) {
      const t = x / 255;
      const a = Math.pow(t, 1.8);
      const half = 0.4 + t * 3.6;
      mc.fillStyle = `rgba(150,190,255,${(a * 0.35).toFixed(3)})`;
      mc.fillRect(x, 4.5 - half, 1, half * 2);
      mc.fillStyle = `rgba(255,255,255,${a.toFixed(3)})`;
      mc.fillRect(x, 4.5 - half * 0.35, 1, Math.max(0.6, half * 0.7));
    }
    mt.refresh();
  }
  env.shoot = scene.add.image(0, 0, 'env-meteor').setOrigin(1, 0.5).setBlendMode('ADD').setAlpha(0);
  env.shootHead = scene.add.image(0, 0, TextureKey.SOFTGLOW).setTint(0xeaf2ff).setBlendMode('ADD').setScale(0.22).setAlpha(0);

  env.layer.add([
    env.sky, env.stars, ...env.twinkles.map(t => t.o), env.shoot, env.shootHead,
    env.moonGlow, env.moon, env.sunGlow, env.sun,
    ...env.clouds.map(c => c.o),
    ...env.birds,
    env.back, env.far, env.castle, ...env.windows, ...env.windowGlows, ...env.torches, env.near, env.floor, env.horizon,
    ...env.braziers.flatMap(b => [b.glow, b.g, b.fire]),
    ...env.motes.map(m => m.o),
    env.vig,
  ]);

  let envT = 0;
  let moonT = 0;
  let shooting = false;
  let flocking = false;
  // the first flock / shooting star comes soon after load, so it's actually seen
  let nextFlockAt = 0;
  let nextShootAt = 0;

  /**
   * Recomputes the sky's colour/position state for a given instant. Heavy
   * canvas redraws (the sky gradient, the moon phase raster) only run when
   * the sun's quantized elevation/minute (or the moon's phase step) has
   * actually moved, or when `force` is passed (boot, or a manual clock jump).
   *
   * @param {Date} date
   * @param {boolean} [force]
   * @return {void}
   */
  /**
   * Places the moon for the last sky frame: after sunset a moon always
   * shows — its real place once risen, peeking over the back ridge until
   * then, and never behind a HUD panel (sky-layer.js's moonDisplay). Also
   * run from tick(), since the panels' zones only arrive after boot.
   *
   * @return {void}
   */
  function placeMoon() {
    if (!env.frame) {
      return;
    }
    const backSky = env['env-backSky'];
    const shownMoon = moonDisplay(env.frame, { width: W, horizonY: HZ }, x => backSky[Math.max(0, Math.min(W - 1, x))], env.moon.displayHeight / 2, scene._zones ?? []);
    env.moon.setPosition(shownMoon.x, shownMoon.y).setAlpha(shownMoon.alpha);
    env.moonGlow.setPosition(shownMoon.x, shownMoon.y).setAlpha(shownMoon.alpha * 0.3);
  }

  function update(date, force) {
    const frame = skyFrame(date, site, { width: W, horizonY: HZ });
    const key = Math.round(frame.elevation * 4) + ':' + date.getMinutes();
    if (force || key !== env.lastKey) {
      env.lastKey = key;
      const [z, m, h] = frame.colors;
      const c = env.skyTex.context;
      const cw = env.skyTex.width;
      const chh = env.skyTex.height;
      const g = c.createLinearGradient(0, 0, 0, chh);
      g.addColorStop(0, z);
      g.addColorStop(0.6, m);
      g.addColorStop(1, h);
      c.fillStyle = g;
      c.fillRect(0, 0, cw, chh);
      if (frame.twilight > 0) {
        const gx = Phaser.Math.Clamp(frame.sun.x, 0, W) / 4;
        const rg = c.createRadialGradient(gx, chh, 1, gx, chh, cw * 0.6);
        rg.addColorStop(0, 'rgba(255,150,90,' + (0.55 * frame.twilight).toFixed(2) + ')');
        rg.addColorStop(1, 'rgba(255,150,90,0)');
        c.fillStyle = rg;
        c.fillRect(0, 0, cw, chh);
      }
      env.skyTex.refresh();
      env.back.setTint(toInt(mixHex(mixHex(h, '#ffffff', 0.08), frame.ambient, 0.25)));
      env.far.setTint(toInt(mixHex(mixHex(h, '#000000', 0.35), frame.ambient, 0.35)));
      // darker and warmer than the range behind it, so the castle stands out instead of melting into the rock
      env.castle.setTint(toInt(mixHex(mixHex(mixHex(h, '#000000', 0.48), '#6b5a4a', 0.25), frame.ambient, 0.3)));
      env.near.setTint(toInt(mixHex(mixHex(h, '#000000', 0.55), frame.ambient, 0.25)));
      // Keep fighters readable on a bright day rather than washing the
      // floor all the way out to the raw ambient colour.
      env.floor.setTint(toInt(mixHex(frame.ambient, '#1a2238', 0.35 + 0.25 * frame.day)));
      env.horizon.setFillStyle(toInt(h), 0.25);
      env.clouds.forEach(cl =>
        cl.o
          .setTint(toInt(mixHex(mixHex('#ffffff', h, frame.twilight * 0.6), '#2a3355', frame.night * 0.8)))
          .setAlpha((0.35 + 0.5 * frame.day + 0.15 * frame.twilight) * cl.base));

      const pk = Math.round(frame.moon.phase * 60);
      if (pk !== env.moonPhaseKey) {
        env.moonPhaseKey = pk;
        const mc = env.moonTex.context;
        const R = 8;
        mc.clearRect(0, 0, 18, 18);
        for (let y = -R; y < R; y++) {
          for (let x = -R; x < R; x++) {
            const nx = (x + 0.5) / R;
            const ny = (y + 0.5) / R;
            if (nx * nx + ny * ny > 1) {
              continue;
            }
            const s = Math.sqrt(1 - ny * ny);
            const k = Math.cos(2 * Math.PI * frame.moon.phase);
            const lit = frame.moon.phase < 0.5 ? nx > k * s : nx < -k * s;
            mc.fillStyle = lit ? ((x + y) % 5 === 0 ? '#c9d3ea' : '#e8eefc') : 'rgba(150,165,200,0.18)';
            mc.fillRect(x + 9, y + 9, 1, 1);
          }
        }
        env.moonTex.refresh();
      }
    }
    env.stars.setAlpha(frame.night);
    env.frame = frame;
    placeMoon();
    env.sun.setPosition(frame.sun.x, frame.sun.y).setAlpha(frame.sun.visible ? 1 : 0)
      .setFillStyle(toInt(mixHex('#fff4c2', '#ff9a4a', frame.sun.warm)));
    env.sunGlow.setPosition(frame.sun.x, frame.sun.y).setAlpha(frame.sun.visible ? 0.9 : 0)
      .setTint(toInt(mixHex('#fff1b0', '#ff8a3a', frame.sun.warm))).setScale((W / 180) * (1 + frame.sun.warm));
    env.night = frame.night;
    env.day = frame.day;
    env.dusk = frame.twilight;
  }

  /**
   * Advances the sky's own animation (cloud drift, star twinkle, brazier
   * flicker, window/mote blink, an occasional bird flock or shooting star)
   * and re-derives its colour state at most once every 20s of real time.
   * Reduced-motion viewers get the plain colour state with no drift, no
   * twinkle/blink/flicker, and no shooting star or bird flock.
   *
   * @param {number} time scene.time.now, ms
   * @param {number} dt seconds since the last frame
   * @return {void}
   */
  function tick(time, dt) {
    if (!envT || time - envT > 20000) {
      envT = time;
      update(new Date());
    }
    if (!moonT || time - moonT > 500) {
      moonT = time;
      placeMoon();
    }
    const dark = Math.max(env.night, env.dusk || 0);
    if (scene.reducedMotion) {
      env.twinkles.forEach(t => t.o.setAlpha(env.night));
      env.braziers.forEach(b => b.glow.setAlpha(0.35 + 0.55 * dark).setScale((W / 220) * (1 + dark) * (b.u / 3)));
      env.windows.forEach(w => w.setAlpha(env.night * 0.85));
      env.windowGlows.forEach(g => g.setAlpha(env.night * 0.5));
      env.torches.forEach(t => t.setAlpha(Math.max(env.night, env.dusk || 0) * 0.8));
      env.motes.forEach(m => m.o.setFillStyle(dark > 0.5 ? 0xd9ff7a : 0xfff3c4).setAlpha(dark > 0.2 ? dark * 0.7 : (env.day || 0) * 0.3));
      return;
    }
    env.clouds.forEach(c => {
      c.o.x += c.sp * dt;
      if (c.o.x - c.o.displayWidth / 2 > W) {
        c.o.x = -c.o.displayWidth / 2;
      }
    });
    env.twinkles.forEach(t => t.o.setAlpha(env.night * (0.3 + 0.7 * (0.5 + 0.5 * Math.sin((time / 1000) * t.sp + t.ph)))));
    // Braziers: flicker, brighter after dark.
    env.braziers.forEach((b, i) => {
      const fl = 0.85 + 0.15 * Math.sin(time / 90 + i * 2) * Math.sin(time / 37 + i);
      b.glow.setAlpha((0.35 + 0.55 * dark) * fl).setScale((W / 220) * (1 + dark) * fl * (b.u / 3));
    });
    env.windows.forEach((w, i) => w.setAlpha(env.night * (0.75 + 0.25 * Math.sin(time / 700 + i * 1.7))));
    env.windowGlows.forEach((g, i) => g.setAlpha(env.night * 0.55 * (0.8 + 0.2 * Math.sin(time / 700 + i * 1.7))));
    env.torches.forEach((t, i) => t.setAlpha(Math.max(env.night, env.dusk || 0) * (0.7 + 0.3 * Math.sin(time / 80 + i * 3) * Math.sin(time / 47 + i))));
    // Dust by day (slow, faint) → fireflies after dusk (warmer, blinking, wandering).
    env.motes.forEach(m => {
      m.o.x += Math.sin(time / 2600 + m.ph) * m.sx * dt;
      m.o.y = m.y0 + Math.sin(time / 1700 + m.ph * 3) * 10;
      if (m.o.x > W) { m.o.x = 0; }
      if (m.o.x < 0) { m.o.x = W; }
      const blink = 0.5 + 0.5 * Math.sin(time / 420 + m.ph * 5);
      m.o.setFillStyle(dark > 0.5 ? 0xd9ff7a : 0xfff3c4)
        .setAlpha(dark > 0.2 ? dark * blink * 0.95 : (env.day || 0) * 0.35 * (0.5 + 0.5 * blink));
    });
    // Birds by day and at dusk, a shooting star at night: each on its own
    // schedule (nextSkyEvent), the first one soon after the page loads.
    if (!nextFlockAt) {
      nextFlockAt = time + nextSkyEvent(true, Math.random);
      nextShootAt = time + nextSkyEvent(true, Math.random);
    }
    if ((env.day || 0) + (env.dusk || 0) > 0.3 && !flocking && time >= nextFlockAt) {
      nextFlockAt = time + nextSkyEvent(false, Math.random);
      flyFlock();
    }
    if (env.night > 0.5 && !shooting && time >= nextShootAt) {
      nextShootAt = time + nextSkyEvent(false, Math.random);
      shootStar();
    }
  }

  /**
   * Sends a flock in a loose V across the sky, each bird flapping on its own
   * phase and bobbing a little.
   *
   * @return {void}
   */
  function flyFlock() {
    flocking = true;
    const y = HZ * (0.15 + Math.random() * 0.3);
    const dir = Math.random() < 0.5 ? 1 : -1;
    const flight = { x: dir > 0 ? -60 : W + 60 };
    scene.tweens.add({
      targets: flight,
      x: dir > 0 ? W + 120 : -120,
      duration: 14000 + Math.random() * 6000,
      onUpdate: () => {
        env.birds.forEach((bd, k) => {
          const f = env.flock[k];
          const flap = Math.sin(scene.time.now / 110 + f.phase);
          bd.setTexture(flap > 0 ? 'env-bird0' : 'env-bird1')
            .setPosition(flight.x + f.dx * px * dir, y + f.dy * px + Math.sin(scene.time.now / 900 + f.phase) * px)
            .setFlipX(dir < 0)
            .setAlpha(0.85);
        });
      },
      onComplete: () => { env.birds.forEach(bd => bd.setAlpha(0)); flocking = false; },
    });
  }

  /**
   * A shooting star (environment/pixel-art.js's meteorPlan/meteorFrame): its
   * tapered trail stretches out behind a glowing head as it falls at a slant,
   * then burns out.
   *
   * @return {void}
   */
  function shootStar() {
    shooting = true;
    const plan = meteorPlan(Math.random, W, HZ);
    scene.tweens.addCounter({
      from: 0,
      to: 1,
      duration: plan.duration,
      onUpdate: tw => {
        const f = meteorFrame(plan, tw.getValue());
        env.shoot.setPosition(f.x, f.y).setRotation(f.rotation).setDisplaySize(f.length, 9).setAlpha(f.alpha);
        env.shootHead.setPosition(f.x, f.y).setAlpha(f.alpha * 0.9).setScale(0.16 + 0.1 * f.alpha);
      },
      onComplete: () => {
        env.shoot.setAlpha(0);
        env.shootHead.setAlpha(0);
        shooting = false;
      },
    });
  }


  /** Debug hook (staging's ?sky-debug=1): sky events on demand. */
  const debug = { flock: flyFlock, shoot: shootStar };

  /** Tears down the environment's own layer (and every child it owns). */
  function destroy() {
    env.layer.destroy();
  }

  /**
   * Forces the sky to today's date at a given time-of-day, for staging
   * verification (`window.__battlefield.env.setClock`, gated on
   * `?sky-debug=1` — see index.js). One-shot: the next scheduled `tick()`
   * redraw (at most 20s later) reads the real clock again.
   *
   * @param {number} minutesOfDay 0..1439
   * @return {void}
   */
  function setClock(minutesOfDay) {
    const d = new Date();
    d.setHours(Math.floor(minutesOfDay / 60), minutesOfDay % 60, 0, 0);
    update(d, true);
  }

  update(new Date(), true);

  return { update, tick, destroy, setClock, debug, horizonY: HZ };
}
