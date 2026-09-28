// Pure pixel-art generators for the battlefield's sky dressing: clouds, the
// far mountains, the near wooded hills, the distant castle and the birds.
// Each returns plain data (tone grids, height profiles, offsets) that
// environment/index.js paints onto its canvases; keeping the shapes here
// means they can be checked without a canvas or Phaser.

/**
 * A pixel cumulus as a grid of tones: 0 empty, 1 shadow (the base and the
 * creases between puffs), 2 body, 3 lit top. The top is a row of small
 * puffs riding a dome, so the edge billows in scallops instead of the one
 * smooth dome (or square block) the earlier clouds were; the base is flat
 * and shadowed, as real cumulus look.
 *
 * @param {number} w Width in pixels.
 * @param {number} h Height in pixels.
 * @param {function(): number} rnd 0..1 generator.
 * @return {number[][]} h rows of w tones.
 */
export function cloudPixels(w, h, rnd) {
  const grid = Array.from({ length: h }, () => new Array(w).fill(0));
  const base = h - 2;
  const inside = (x, y, e) => ((x - e.cx) / e.rx) ** 2 + ((y - e.cy) / e.ry) ** 2 <= 1;
  // the body: two or three broad, low ellipses sitting on the flat base
  const body = [];
  const lobes = 2 + Math.floor(rnd() * 2);
  for (let i = 0; i < lobes; i++) {
    const t = (i + 0.5) / lobes;
    const ry = h * (0.34 + 0.22 * Math.sin(Math.PI * t) + rnd() * 0.08);
    body.push({ cx: w * (0.14 + 0.72 * t), cy: base - ry * 0.35, rx: w * (0.2 + rnd() * 0.08), ry });
  }
  const bodyTop = x => {
    for (let y = 0; y <= base; y++) {
      if (body.some(e => inside(x + 0.5, y + 0.5, e))) {
        return y;
      }
    }

    return base + 1;
  };
  // the billows: overlapping round puffs along the body's upper edge
  const puffs = [];
  const r0 = Math.max(2, h * 0.15);
  for (let x = w * 0.1; x < w * 0.92; x += r0 * (1.2 + rnd() * 0.5)) {
    const top = bodyTop(Math.round(x));
    if (top <= base) {
      const r = r0 * (0.75 + rnd() * 0.5);
      puffs.push({ cx: x, cy: top + r * 0.55, rx: r, ry: r });
    }
  }
  for (let y = 0; y <= base; y++) {
    for (let x = 1; x < w - 1; x++) {
      if (body.some(e => inside(x + 0.5, y + 0.5, e)) || puffs.some(e => inside(x + 0.5, y + 0.5, e))) {
        grid[y][x] = 2;
      }
    }
  }
  for (let x = 0; x < w; x++) {
    const top = grid.findIndex(row => row[x] > 0);
    if (top < 0) {
      continue;
    }
    for (let y = top; y <= base; y++) {
      if (!grid[y][x]) {
        continue;
      }
      grid[y][x] = y - top < 2 ? 3 : y >= base - 1 ? 1 : 2;
    }
  }
  // creases: shade the dip where two billows meet
  const col = c => grid.findIndex(row => row[c] > 0);
  for (let x = 2; x < w - 2; x++) {
    const [l, m, r] = [col(x - 2), col(x), col(x + 2)];
    if (m > 0 && l >= 0 && r >= 0 && m > l && m > r) {
      grid[m][x] = 1;
    }
  }

  return grid;
}

/**
 * A cloud bank: three or four cumulus of different sizes overlapped into one
 * wider cluster, so the sky has banks as well as lone clouds.
 *
 * @param {number} w
 * @param {number} h
 * @param {function(): number} rnd 0..1 generator.
 * @return {number[][]}
 */
export function cloudBank(w, h, rnd) {
  const grid = Array.from({ length: h }, () => new Array(w).fill(0));
  const count = 3 + Math.floor(rnd() * 2);
  for (let i = 0; i < count; i++) {
    const cw = Math.round(w * (0.38 + rnd() * 0.22));
    const ch = Math.round(h * (0.55 + (i % 2 ? 0 : 0.45) * rnd()));
    const ox = Math.round((i / (count - 1)) * (w - cw));
    const oy = h - ch;
    // fill only what's still empty, so the bank reads as one mass without
    // each cloud's lit edge drawn as a seam across the one behind it
    cloudPixels(cw, ch, rnd).forEach((row, y) => row.forEach((tone, x) => {
      if (tone && oy + y < h && ox + x < w && !grid[oy + y][ox + x]) {
        grid[oy + y][ox + x] = tone;
      }
    }));
  }

  return grid;
}

/**
 * A mountain range's skyline: one y per x inside [lo, hi] (smaller is
 * higher), from a few layered sine waves with the slope limited, so it reads
 * as peaks and valleys rather than the old flat-topped steps that looked like
 * buildings.
 *
 * @param {number} width
 * @param {number} lo Highest a peak may reach.
 * @param {number} hi Lowest a valley may sink.
 * @param {function(): number} rnd 0..1 generator.
 * @return {number[]}
 */
export function mountainProfile(width, lo, hi, rnd) {
  const band = hi - lo;
  // a few big peaks of varied height and width…
  const peaks = [];
  for (let x = width * rnd() * 0.08; x < width * 1.05; x += width * (0.12 + rnd() * 0.18)) {
    peaks.push({ x, h: band * (0.4 + rnd() * 0.6), w: width * (0.08 + rnd() * 0.1) });
  }
  peaks[Math.floor(rnd() * peaks.length)].h = band; // one reaches the top of the band
  // …with fine crags from midpoint displacement riding on them
  let n = 1;
  while (n < width) {
    n *= 2;
  }
  const crag = new Array(n + 1).fill(0);
  let amp = band * 0.22;
  for (let step = n; step > 1; step /= 2) {
    const half = step / 2;
    for (let i = half; i < n; i += step) {
      crag[i] = (crag[i - half] + crag[i + half]) / 2 + (rnd() - 0.5) * amp;
    }
    amp *= step > 16 ? 0.55 : 0.8; // keep the last few octaves: those are the crags you see
  }
  // pixel-scale crags: a bounded walk that notches the slopes every few columns
  const notch = [];
  for (let x = 0, v = 0; x < width; x++) {
    if (x % 2 === 0) {
      const step = rnd() < 0.5 ? -1 : 1;
      v = Math.abs(v + step) > 3 ? v - step : v + step;
    }
    notch.push(v);
  }
  const raw = Array.from({ length: width }, (_, x) => {
    let lift = band * 0.1; // foothills: the range never sinks to the floor
    for (const p of peaks) {
      const d = Math.abs(x - p.x) / p.w;
      if (d < 1) {
        lift = Math.max(lift, p.h * (1 - d));
      }
    }

    return Math.round(Math.max(lo, Math.min(hi, hi - lift + crag[x] + notch[x])));
  });

  return limitSlope(raw, 3, lo, hi);
}

/**
 * Rolling wooded hills: a gentler skyline than the mountains, with pine
 * trees standing on its crest.
 *
 * @param {number} width
 * @param {number} lo
 * @param {number} hi
 * @param {function(): number} rnd 0..1 generator.
 * @return {{profile: number[], trees: Array<{x: number, y: number, h: number, kind: string}>}}
 */
export function hillProfile(width, lo, hi, rnd) {
  const waves = [
    { a: 0.35, l: width / (1.6 + rnd()), p: rnd() * 6 },
    { a: 0.15, l: width / (4 + rnd() * 2), p: rnd() * 6 },
  ];
  const profile = limitSlope(sampleWaves(width, lo, hi, waves), 2, lo, hi);
  const trees = forestLayout(profile, rnd);

  return { profile, trees };
}

/**
 * Samples summed sine waves into integer heights inside [lo, hi].
 *
 * @param {number} width
 * @param {number} lo
 * @param {number} hi
 * @param {Array<{a: number, l: number, p: number}>} waves Amplitude (share of the band), wavelength, phase.
 * @return {number[]}
 */
function sampleWaves(width, lo, hi, waves) {
  const mid = (lo + hi) / 2;
  const span = (hi - lo) / 2;

  return Array.from({ length: width }, (_, x) => {
    const v = waves.reduce((sum, w) => sum + w.a * Math.sin((x / w.l) * Math.PI * 2 + w.p), 0);

    return Math.round(Math.max(lo, Math.min(hi, mid + v * span * 1.6)));
  });
}

/**
 * Limits how far the skyline may move between neighbouring columns.
 *
 * @param {number[]} ys
 * @param {number} step Largest change per column.
 * @param {number} lo
 * @param {number} hi
 * @return {number[]}
 */
function limitSlope(ys, step, lo, hi) {
  const out = [ys[0]];
  for (let x = 1; x < ys.length; x++) {
    const d = Math.max(-step, Math.min(step, ys[x] - out[x - 1]));
    out.push(Math.max(lo, Math.min(hi, out[x - 1] + d)));
  }

  return out;
}

/**
 * The distant castle: two towers with pointed roofs, a taller keep between
 * them, a crenellated curtain wall, a gate and a flag, as tones 0 empty,
 * 1 dark (shadowed faces, windows, gate), 2 mid, 3 lit faces.
 *
 * @return {number[][]}
 */
export function castlePixels() {
  const art = [
    '.....................3.......................',
    '.....................33......................',
    '.....................3.......................',
    '.....................2.......................',
    '....................323......................',
    '...................33222.....................',
    '..................3332222....................',
    '.....3...........333322222...........3.......',
    '....323.........3333222222..........323......',
    '...33222........3333222222.........33222.....',
    '..3332222.......3.3.22.2.2........3332222....',
    '.333322222......3333222222.......333322222...',
    '..3332222.......3331122222........3332222....',
    '..3.3.2.2.......3331122222........3.3.2.2....',
    '..3332222.......3333222222........3332222....',
    '..3311222.......3333222222........3311222....',
    '..3311222.3.3.2.3333222222.3.3.2..3311222....',
    '..3332222333322223333222222333322223332222....',
    '..3332222333322223333222222333322223332222....',
    '..3332222333322223311122222333322223332222....',
    '..3332222333322223311122222333322223332222....',
    '..3332222333322223311122222333322223332222....',
    '..3332222333322223311122222333322223332222....',
  ];
  const width = Math.max(...art.map(r => r.length));

  return art.map(r => Array.from(r.padEnd(width, '.'), ch => (ch === '.' ? 0 : Number(ch))));
}

/**
 * A small bird's two flapping frames (wings up, wings down), 'X' inked.
 *
 * @type {string[][]}
 */
export const BIRD_FRAMES = [
  ['X.....X', '.X...X.', '..XXX..', '...X...'],
  ['.......', '.......', 'XXXXXXX', '.X.X.X.'],
];

/**
 * Offsets for a flock of `n` birds in a loose V behind its leader (dx < 0
 * trails the direction of flight), alternating arms, with a little jitter
 * and each bird's own flap phase.
 *
 * @param {number} n
 * @param {function(): number} rnd 0..1 generator.
 * @return {Array<{dx: number, dy: number, phase: number}>}
 */
export function flockLayout(n, rnd) {
  return Array.from({ length: n }, (_, k) => {
    if (k === 0) {
      return { dx: 0, dy: 0, phase: 0 };
    }
    const rank = Math.ceil(k / 2);
    const side = k % 2 ? -1 : 1;

    return { dx: -rank * (11 + rnd() * 4), dy: side * rank * (6 + rnd() * 3), phase: rnd() * Math.PI * 2 };
  });
}

/**
 * The tone a mountain column's face takes, from the average skyline a few
 * columns either side (not the next column alone, nor a single point, which
 * flip on every rocky wobble and striped the faces): 3 lit where the ridge rises to
 * the right, 1 shadow where it falls, 2 body on the flats.
 *
 * @param {number[]} sky The skyline, one y per column.
 * @param {number} x
 * @return {number}
 */
export function faceTone(sky, x) {
  const reach = 6;
  const mean = (a, b) => {
    const from = Math.max(0, a);
    const to = Math.min(sky.length - 1, b);
    let sum = 0;
    for (let i = from; i <= to; i++) {
      sum += sky[i];
    }

    return sum / (to - from + 1);
  };
  // compare the average skyline either side: small rocky wobbles average out
  const slope = mean(x - reach, x - 1) - mean(x + 1, x + reach);
  if (slope > 2) {
    return 3;
  }

  return slope < -2 ? 1 : 2;
}

/**
 * Height range per tree kind, in pixels.
 *
 * @type {Object<string, number[]>}
 */
const TREE_SIZES = { pine: [8, 12], tall: [12, 17], round: [7, 10], bush: [3, 5] };

/**
 * Where trees stand along a hill line: clumps of three to eight of one
 * dominant kind (with the odd other kind mixed in) and clearings between
 * clumps, sizes varying — instead of an evenly spaced row of one tree.
 *
 * @param {number[]} profile The hill line, one y per x.
 * @param {function(): number} rnd 0..1 generator.
 * @return {Array<{x: number, y: number, h: number, kind: string}>}
 */
export function forestLayout(profile, rnd) {
  const kinds = Object.keys(TREE_SIZES);
  const trees = [];
  let x = Math.floor(rnd() * 20);
  while (x < profile.length - 3) {
    const main = kinds[Math.floor(rnd() * kinds.length)];
    const size = 3 + Math.floor(rnd() * 6);
    for (let i = 0; i < size && x < profile.length - 3; i++) {
      const kind = rnd() < 0.2 ? kinds[Math.floor(rnd() * kinds.length)] : main;
      const [a, b] = TREE_SIZES[kind];
      trees.push({ x, y: profile[x], h: a + Math.floor(rnd() * (b - a + 1)), kind });
      x += 3 + Math.floor(rnd() * 6);
    }
    x += 20 + Math.floor(rnd() * 50); // a clearing before the next clump
  }

  return trees;
}

/**
 * One tree as a tone grid (1 shadowed side, 2 body, 3 lit side; trunks 1),
 * its base centred on the bottom row.
 *
 * @param {string} kind pine, tall, round or bush.
 * @param {number} h Height in pixels.
 * @return {number[][]}
 */
export function treePixels(kind, h) {
  const half = kind === 'tall' ? Math.max(1, Math.round(h * 0.2)) : kind === 'bush' ? Math.max(2, h) : Math.max(2, Math.round(h * 0.38));
  const w = half * 2 + 1;
  const grid = Array.from({ length: h }, () => new Array(w).fill(0));
  const shade = (x, y) => (x < half ? 3 : x > half ? 1 : 2);
  if (kind === 'pine' || kind === 'tall') {
    const crown = h - 1;
    for (let y = 0; y < crown; y++) {
      const tier = (y % 3) / 3; // stepped tiers read as boughs
      const r = Math.round(((y + 1) / crown) * half * (0.75 + tier * 0.3));
      for (let x = half - r; x <= half + r; x++) {
        grid[y][x] = shade(x, y);
      }
    }
    grid[h - 1][half] = 1;
  } else if (kind === 'round') {
    const cy = Math.round((h - 2) * 0.45);
    const r = Math.min(half, Math.round((h - 2) * 0.5));
    for (let y = 0; y < h - 2; y++) {
      for (let x = 0; x < w; x++) {
        if ((x - half) ** 2 + ((y - cy) * 1.1) ** 2 <= r * r) {
          grid[y][x] = shade(x, y);
        }
      }
    }
    grid[h - 2][half] = 1;
    grid[h - 1][half] = 1;
  } else {
    for (let y = 0; y < h; y++) {
      const r = Math.round(half * Math.sqrt(1 - ((h - 1 - y - h) / h) ** 2 * 0.2) * ((y + 1) / h) ** 0.4);
      for (let x = half - r; x <= half + r; x++) {
        grid[y][x] = shade(x, y);
      }
    }
  }

  return grid;
}

/**
 * Milliseconds until the next sky event (a bird flock by day, a shooting
 * star by night): the first soon after the page loads, so it is actually
 * seen, then every fifteen to forty-five seconds.
 *
 * @param {boolean} first Whether this is the first since load.
 * @param {function(): number} rnd 0..1 generator.
 * @return {number}
 */
export function nextSkyEvent(first, rnd) {
  return first ? 4000 + rnd() * 8000 : 15000 + rnd() * 30000;
}

/**
 * The shading for a whole range: each peak lights its left face and shades
 * its right one, split along the peak's spine, with the valley between two
 * peaks as the boundary — the way pixel-art mountains are lit, instead of
 * one tone per column (which reads as vertical bands). Rock darkens toward
 * the base, mist rises pale from it, and snow with a ragged edge caps the
 * high ground. Returns a shader for any pixel of the range.
 *
 * @param {number[]} sky The skyline, one y per column.
 * @param {{lo: number, hi: number, base: number}} band The range's peak band and the y where it meets the ground.
 * @return {function(number, number): {grey: number, snow: boolean}}
 */
export function mountainShader(sky, band) {
  // peaks: the highest point within ±8 columns
  const peaks = [];
  for (let x = 0; x < sky.length; x++) {
    let best = true;
    for (let i = Math.max(0, x - 8); i <= Math.min(sky.length - 1, x + 8) && best; i++) {
      if (sky[i] < sky[x] || (sky[i] === sky[x] && i < x)) {
        best = false;
      }
    }
    if (best) {
      peaks.push(x);
    }
  }
  if (!peaks.length) {
    peaks.push(sky.indexOf(Math.min(...sky)));
  }
  // each column belongs to the peak on its side of the valley bottom between
  // two peaks (the lowest point between them), not simply the nearest peak
  const owner = new Array(sky.length);
  const spans = new Map();
  let from = 0;
  peaks.forEach((p, i) => {
    let to = sky.length - 1;
    if (i + 1 < peaks.length) {
      const next = peaks[i + 1];
      to = p;
      for (let x = p; x <= next; x++) {
        if (sky[x] >= sky[to]) {
          to = x;
        }
      }
    }
    for (let x = from; x <= to; x++) {
      owner[x] = p;
    }
    spans.set(p, { from, to });
    from = to + 1;
  });
  const snowline = band.lo + (band.hi - band.lo) * 0.3;
  const mistTop = band.hi - (band.hi - band.lo) * 0.1;

  return (x, y) => {
    const top = sky[x];
    // valley seams slant as they run down, instead of dropping plumb to the ground
    const lean = Math.round((y - top) * 0.35);
    const peak = owner[Math.max(0, Math.min(sky.length - 1, x - lean))];
    const down = y - sky[peak];
    const span = spans.get(peak) ?? { from: 0, to: sky.length - 1 };
    // the spine wanders a little and drifts toward the middle of its own
    // peak's slopes as it descends, like a ridge running down the mountain
    const drift = ((span.from + span.to) / 2 - peak) / Math.max(1, band.base - sky[peak]);
    const spine = peak + Math.round(Math.sin(down * 0.7 + peak) * 1.2 + down * drift * 0.8);
    const bayer = [[0, 2], [3, 1]][y % 2][x % 2];
    const nearSpine = Math.abs(x - spine) <= 1;
    const lit = nearSpine ? bayer >= 2 : x < spine;
    let grey = lit ? 232 : 168;
    const depth = Math.min(1, (y - top) / Math.max(1, band.base - top));
    grey -= 22 * depth;

    const ragged = ((x * 7919 + peak) % 5) - 2;
    const snowDepth = Math.max(0, (snowline - sky[peak]) * 0.9 - Math.abs(x - peak) * 0.35 + ragged);
    const snow = y - top < snowDepth;
    if (snow) {
      grey = lit ? 255 : 214;
    }

    if (y > mistTop) {
      const m = Math.min(1, (y - mistTop) / Math.max(1, band.base - mistTop));
      const stepped = Math.min(1, Math.max(0, m + (bayer / 4 - 0.375) * 0.5));
      grey += (246 - grey) * stepped * 0.85;
    }

    return { grey: Math.round(Math.max(0, Math.min(255, grey))), snow };
  };
}

/**
 * One mountain pixel's shade — {@see mountainShader}, for a single pixel.
 *
 * @param {number[]} sky
 * @param {number} x
 * @param {number} y
 * @param {{lo: number, hi: number, base: number}} band
 * @return {{grey: number, snow: boolean}}
 */
export function mountainPixel(sky, x, y, band) {
  return mountainShader(sky, band)(x, y);
}

/**
 * A shooting star's flight: where it starts in the upper sky, its falling
 * slant (degrees below horizontal), which way it heads, how far and how
 * fast it goes, its full trail length, and how bright it is (now and then a
 * fainter one).
 *
 * @param {function(): number} rnd 0..1 generator.
 * @param {number} width World width.
 * @param {number} horizonY
 * @return {{x0: number, y0: number, angle: number, dir: number, distance: number, duration: number, length: number, bright: number}}
 */
export function meteorPlan(rnd, width, horizonY) {
  const faint = rnd() < 0.3;

  return {
    x0: width * (0.2 + rnd() * 0.6),
    y0: horizonY * (0.05 + rnd() * 0.35),
    angle: 18 + rnd() * 24,
    dir: rnd() < 0.5 ? -1 : 1,
    distance: width * (faint ? 0.1 : 0.16 + rnd() * 0.1),
    duration: faint ? 500 + rnd() * 250 : 700 + rnd() * 500,
    length: width * (faint ? 0.04 : 0.07 + rnd() * 0.04),
    bright: faint ? 0.55 : 1,
  };
}

/**
 * A shooting star at `k` (0..1) of its flight: the head's position along
 * the slant (easing in, like something falling), the trail length — which
 * stretches out behind the head, then shrinks as it burns out — and its
 * brightness, flaring in quickly and fading at the end.
 *
 * @param {{x0: number, y0: number, angle: number, dir: number, distance: number, length: number, bright: number}} plan
 * @param {number} k Progress 0..1.
 * @return {{x: number, y: number, length: number, alpha: number, rotation: number}}
 */
export function meteorFrame(plan, k) {
  const t = Math.max(0, Math.min(1, k));
  const travelled = plan.distance * (t * t * 0.4 + t * 0.6);
  const rad = (plan.angle * Math.PI) / 180;
  const stretch = t < 0.35 ? t / 0.35 : 1 - Math.max(0, (t - 0.6) / 0.4) * 0.85;
  const alpha = (t < 0.12 ? t / 0.12 : t > 0.7 ? 1 - (t - 0.7) / 0.3 : 1) * plan.bright;

  return {
    x: plan.x0 + Math.cos(rad) * travelled * plan.dir,
    y: plan.y0 + Math.sin(rad) * travelled,
    length: plan.length * Math.max(0.05, stretch),
    alpha: Math.max(0, alpha),
    // the trail points back along the path, away from the head
    rotation: plan.dir > 0 ? rad : Math.PI - rad,
  };
}
