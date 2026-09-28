// Background landmarks built from the real marks of the tools the team
// fights with (environment/logo-paths.js) — a windmill turning Claude's
// starburst on the hills, a patch of OpenAI-knot flowers on the right-hand
// hill crest, the Antigravity arch floating on its own little island, a
// banner with Cursor's cube on the castle and Gemini's sparkle at night. Pure decoration: nothing
// here reads usage data. Colour grids hold a `#rrggbb` string per inked cell
// (null = empty); tone grids hold 1..3 like pixel-art.js's, tinted per minute
// with the scene.

/**
 * Builds an empty `size` × `size` colour grid.
 *
 * @param {number} w
 * @param {number} h
 * @return {Array<Array<string|null>>}
 */
function blank(w, h) {
  return Array.from({ length: h }, () => Array(w).fill(null));
}

/**
 * A small stone windmill: a tower tapering to a pointed cap, lit on its
 * left, a door at the foot. `hub` is where the sails turn.
 *
 * @return {{art: number[][], hub: {x: number, y: number}}}
 */
export function windmillPixels() {
  const w = 13;
  const h = 24;
  const cx = 6;
  const art = Array.from({ length: h }, () => Array(w).fill(0));
  for (let y = 0; y < h; y++) {
    // cap: a small pointed roof; tower: widening from 5 to 11 px
    const half = y < 5 ? Math.floor(y * 0.8) : Math.round(2.5 + ((y - 5) / (h - 6)) * 3);
    for (let x = cx - half; x <= cx + half; x++) {
      art[y][x] = y < 5 ? (x <= cx ? 3 : 2) : (x < cx - half / 3 ? 3 : x > cx + half / 2 ? 1 : 2);
    }
  }
  // door and a window
  for (let y = h - 5; y < h; y++) {
    art[y][cx] = 1;
    art[y][cx + 1] = 1;
  }
  art[11][cx] = 1;

  return { art, hub: { x: cx, y: 4 } };
}

/**
 * Converts a supersampled RGBA render into one cell per `samples`×`samples`
 * block: lit where at least `cut` of the block is covered, in the average
 * colour of what covers it — so a real logo lands on the pixel grid crisp,
 * without the grey fringe a plain downscale leaves.
 *
 * @param {Uint8ClampedArray} data RGBA, `size * samples` px square.
 * @param {number} size Output cells per side.
 * @param {number} samples Supersampling factor.
 * @param {number} cut Coverage (0..1) a cell needs to be lit.
 * @return {Array<Array<string|null>>}
 */
export function pixelate(data, size, samples, cut) {
  const side = size * samples;
  const hex = v => Math.round(v).toString(16).padStart(2, '0');

  return Array.from({ length: size }, (_, y) => Array.from({ length: size }, (_, x) => {
    let a = 0;
    let r = 0;
    let g = 0;
    let b = 0;
    for (let j = 0; j < samples; j++) {
      for (let i = 0; i < samples; i++) {
        const k = ((y * samples + j) * side + x * samples + i) * 4;
        const alpha = data[k + 3] / 255;
        a += alpha;
        r += data[k] * alpha;
        g += data[k + 1] * alpha;
        b += data[k + 2] * alpha;
      }
    }
    if (a / (samples * samples) < cut) {
      return null;
    }

    return `#${hex(r / a)}${hex(g / a)}${hex(b / a)}`;
  }));
}

/**
 * A flower's stalk `h` px tall, 7 wide: a two-tone stem with a leaf off each
 * side at different heights. The bloom (OpenAI's knot) is its own image,
 * turning on top.
 *
 * @param {number} h
 * @return {Array<Array<string|null>>}
 */
export function stemPixels(h) {
  const g = blank(7, h);
  for (let y = 0; y < h; y++) {
    g[y][3] = y % 3 ? '#3f7f3c' : '#4f9a4a';
  }
  const leaf = (row, cols) => cols.forEach(([x, dy], i) => {
    if (row + dy < h) {
      g[row + dy][x] = i % 2 ? '#6fbf62' : '#4f9a4a';
    }
  });
  leaf(Math.round(h * 0.45), [[2, 0], [1, -1], [0, -1], [1, 0]]);
  leaf(Math.round(h * 0.7), [[4, 0], [5, -1], [6, -1], [5, 0]]);

  return g;
}

/**
 * A little patch of OpenAI-knot flowers on the hill crest: three stalks of
 * different heights around `spot`, each rooted on the crest where it stands
 * and swaying on its own phase.
 *
 * @param {{x: number}} spot The patch's centre.
 * @param {number} unit Screen px per art pixel.
 * @param {function(number): number} crest the hills' crest y at a screen x.
 * @param {function(): number} rnd 0..1 generator.
 * @return {Array<{x: number, y: number, h: number, phase: number}>}
 */
export function flowerBed(spot, unit, crest, rnd) {
  const heights = [16, 22, 12];
  const turn = Math.floor(rnd() * 3);

  return [0, 1, 2].map(k => {
    const x = Math.round(spot.x + unit * (-14 + k * 12 + rnd() * 2));

    return { x, y: crest(x) + unit, h: heights[(k + turn) % 3], phase: rnd() * Math.PI * 2 };
  });
}

/**
 * A flower head that reads as the ChatGPT app icon: `mark` (OpenAI's knot,
 * white) on a round disc of ChatGPT's green.
 *
 * @param {Array<Array<string|null>>} mark
 * @return {Array<Array<string|null>>}
 */
export function bloomPixels(mark) {
  const n = Math.max(mark.length, mark[0].length) + 4;
  const c = (n - 1) / 2;
  const g = blank(n, n);
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      if ((x - c) ** 2 + (y - c) ** 2 <= (n / 2) ** 2 - 1) {
        g[y][x] = '#10a37f';
      }
    }
  }
  const ox = Math.floor((n - mark[0].length) / 2);
  const oy = Math.floor((n - mark.length) / 2);
  mark.forEach((row, y) => row.forEach((col, x) => {
    if (col) {
      g[y + oy][x + ox] = col;
    }
  }));

  return g;
}

/**
 * A banner hanging from a brass rod, `mark` (Cursor's cube) on dark cloth
 * with a swallowtail cut into its foot — the castle's own heraldry.
 *
 * @param {Array<Array<string|null>>} mark
 * @return {Array<Array<string|null>>}
 */
export function bannerPixels(mark) {
  const pad = 2;
  const w = mark[0].length + pad * 2;
  const h = mark.length + pad * 2 + 3;
  const g = blank(w, h);
  const mid = (w - 1) / 2;
  for (let y = 0; y < h; y++) {
    const notch = y - (h - 4);
    for (let x = 0; x < w; x++) {
      if (notch > 0 && Math.abs(x - mid) < notch) {
        continue;
      }
      g[y][x] = y === 0 ? '#b08a4a' : x === 0 ? '#3d4768' : '#27304b';
    }
  }
  mark.forEach((row, y) => row.forEach((c, x) => {
    if (c) {
      g[y + pad][x + pad] = c;
    }
  }));

  return g;
}

/**
 * A floating island `w` × `h`: a grassy top over rock that narrows to a
 * ragged point, lit on its left — the Antigravity mark floats on top of it.
 *
 * @param {number} w
 * @param {number} h
 * @param {function(): number} rnd 0..1 generator.
 * @return {Array<Array<string|null>>}
 */
export function islandPixels(w, h, rnd) {
  const g = blank(w, h);
  const cx = (w - 1) / 2;
  for (let y = 0; y < h; y++) {
    const f = y < 3 ? 1 : Math.pow(1 - (y - 3) / (h - 3), 0.9);
    const half = Math.max(0, (w / 2 - 1) * f + (y > 2 ? (rnd() - 0.5) * 2 : 0) - (y === 0 ? 2 : 0));
    for (let x = 0; x < w; x++) {
      const dx = x - cx;
      if (Math.abs(dx) > half) {
        continue;
      }
      if (y < 3) {
        g[y][x] = y === 0 || (x + y) % 5 === 0 ? '#6fbf62' : '#4f9a4a';
      } else {
        g[y][x] = dx < -half / 3 ? '#a39688' : dx > half / 2 ? '#5e554f' : '#7d7168';
      }
    }
  }
  return g;
}

/**
 * Where the landmarks go, from the world box alone: the windmill on the hill
 * crest well away from the castle and the boss's centre column, the island
 * and the Gemini star up in the sky — all clear of the HUD's fixed corners
 * (the Team panel's left quarter, the boss plate's top middle third). `unit`
 * is their screen px per art pixel.
 *
 * @param {{width: number, horizonY: number, keepX: number}} box
 * @param {function(number): number} hillSky the hills' crest y at a screen x.
 * @return {{unit: number, windmill: {x: number, y: number}, flowers: {x: number}, island: {x: number, y: number}, star: {x: number, y: number}}}
 */
export function landmarkLayout({ width, horizonY, keepX }, hillSky) {
  const unit = Math.max(2, Math.round(width / 400));
  // between the Team panel (the left quarter) and the boss's centre column
  const spot = [0.34, 0.3, 0.62]
    .map(f => Math.round(width * f))
    .find(x => Math.abs(x - keepX) > width * 0.2 && Math.abs(x - width / 2) > width * 0.15) ?? Math.round(width * 0.34);

  return {
    unit,
    windmill: { x: spot, y: hillSky(spot) },
    // the OpenAI flowers on the far right of the hill crest, right of the castle
    flowers: { x: Math.round(width * 0.9) },
    // right of the boss's head and low enough that the mark hovering over it
    // clears the boss plate; the star in the gap right of the Team panel,
    // left of the plate
    island: { x: Math.round(width * 0.63), y: Math.round(horizonY * 0.7) },
    star: { x: Math.round(width * 0.29), y: Math.round(horizonY * 0.24) },
  };
}
