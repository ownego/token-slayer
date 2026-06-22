import Phaser from 'phaser';
import { bus } from './bus.js';

const TOP_ROWS = 5;

const PANEL_W    = 240;
const PANEL_TOP  = 5;
const PANEL_PAD  = 4;
const INNER_PAD  = 12;
const TITLE_H    = 24;
const SEP_H      = 2;
const ROW_H      = 22;
const PANEL_H    = INNER_PAD + TITLE_H + SEP_H + TOP_ROWS * ROW_H + INNER_PAD;
const HANDLE_MAX = 10;
const DMG_W      = 5;
const RANK_W     = 27;  // pixel width of "1. " prefix in 15px monospace

const RANK_COLORS  = ['#fbbf24', '#e2e8f0', '#f97316', '#94a3b8', '#64748b'];
const DMG_COLOR    = '#38bdf8';
const TITLE_COLOR  = '#fbbf24';
const BORDER_COLOR = 0xfbbf24;
const BG_COLOR     = 0x0b1629;

export function createLeaderboard(scene) {
  const fighters = new Map();
  const isPortrait = scene.mode === 'portrait';

  const W = scene.layout.logicalWidth;
  // Landscape: top-right corner.  Portrait: top-left corner (fighters start at y≈540, boss is depth 5 so renders above panel).
  const panL    = isPortrait ? PANEL_PAD : W - PANEL_PAD - PANEL_W;
  const panTopY = PANEL_TOP; // y=5 for both modes

  const gfx = scene.add.graphics().setDepth(3);
  gfx.fillStyle(BG_COLOR, 0.93);
  gfx.fillRect(panL, panTopY, PANEL_W, PANEL_H);
  gfx.lineStyle(2, BORDER_COLOR, 1);
  gfx.strokeRect(panL, panTopY, PANEL_W, PANEL_H);

  gfx.fillStyle(0xfbbf24, 1);
  for (const [ox, oy] of [
    [panL - 2,           panTopY - 2],
    [panL + PANEL_W - 3, panTopY - 2],
    [panL - 2,           panTopY + PANEL_H - 3],
    [panL + PANEL_W - 3, panTopY + PANEL_H - 3],
  ]) {
    gfx.fillRect(ox, oy, 5, 5);
  }

  const sepY = panTopY + INNER_PAD + TITLE_H;
  gfx.lineStyle(1, BORDER_COLOR, 0.4);
  gfx.lineBetween(panL + INNER_PAD, sepY, panL + PANEL_W - INNER_PAD, sepY);

  const titleText = scene.addSharpText(panL + INNER_PAD, panTopY + INNER_PAD, '▸ TOP DAMAGE', {
    fontFamily: 'monospace',
    fontSize: '16px',
    color: TITLE_COLOR,
    stroke: '#060d1f',
    strokeThickness: 4,
  }, 3).setOrigin(0, 0).setDepth(4);

  const rowsStartY = sepY + SEP_H + ROW_H / 2;
  const nameX      = panL + INNER_PAD + 2 + RANK_W;

  const rows = Array.from({ length: TOP_ROWS }, (_, i) => {
    const y     = rowsStartY + i * ROW_H;
    const style = { fontFamily: 'monospace', fontSize: '15px', color: RANK_COLORS[i], stroke: '#060d1f', strokeThickness: 3 };

    const rank = scene.addSharpText(panL + INNER_PAD + 2, y, '', style, 3)
      .setOrigin(0, 0.5).setDepth(4);
    const name = scene.addSharpText(nameX, y, '', style, 3)
      .setOrigin(0, 0.5).setDepth(4);
    const right = scene.addSharpText(panL + PANEL_W - INNER_PAD, y, '', {
      fontFamily: 'monospace', fontSize: '15px', color: DMG_COLOR, stroke: '#060d1f', strokeThickness: 3,
    }, 3).setOrigin(1, 0.5).setDepth(4);

    return { rank, name, right };
  });

  // Shimmer + DOOM fire only for landscape (portrait panel is compact, skip for perf).
  const SHIMMER_COLORS = ['#ffcc00', '#ff8800', '#ff4400'];
  const SHIMMER_ALPHA  = [0.75, 0.55, 0.32];

  const shimmers = isPortrait ? [] : rows.slice(0, 3).map((_, i) => {
    const y = rowsStartY + i * ROW_H;
    const s = scene.addSharpText(nameX, y - 1, '', {
      fontFamily: 'monospace', fontSize: '15px', color: SHIMMER_COLORS[i],
    }, 3).setOrigin(0, 0.5).setDepth(3.5).setAlpha(0).setVisible(false)
      .setBlendMode(Phaser.BlendModes.ADD);
    scene.tweens.add({
      targets: s,
      alpha: { from: SHIMMER_ALPHA[i] * 0.25, to: SHIMMER_ALPHA[i] },
      duration: 120 + i * 50, delay: i * 80,
      ease: 'Sine.easeInOut', yoyo: true, repeat: -1,
    });
    return s;
  });

  const fires = isPortrait ? [] : rows.slice(0, 3).map((_, i) => {
    const baseY = rowsStartY + i * ROW_H + 7;
    return createDoomFire(scene, nameX, baseY, i);
  });

  let fireUpdateHandler = null;
  if (!isPortrait) {
    fireUpdateHandler = () => {
      for (const f of fires) if (f.active) f.tick();
    };
    scene.events.on('update', fireUpdateHandler);
  }

  function render() {
    const top = ranked(fighters).slice(0, TOP_ROWS);
    for (let i = 0; i < TOP_ROWS; i++) {
      if (top[i]) {
        const [userId, entry] = top[i];
        const handle = fitHandle(resolveHandle(scene, userId, entry.handle));
        rows[i].rank.setText(`${i + 1}.`);
        rows[i].name.setText(handle);
        rows[i].right.setText(abbreviateDamage(entry.damage).padStart(DMG_W));
        if (!isPortrait && i < 3) {
          shimmers[i].setText(handle).setVisible(true);
          fires[i].show(rows[i].name.width);
        }
      } else {
        rows[i].rank.setText('');
        rows[i].name.setText('');
        rows[i].right.setText('');
        if (!isPortrait && i < 3) {
          shimmers[i].setText('').setVisible(false);
          fires[i].hide();
        }
      }
    }
    // Keep Alpine.js overlay data fresh for portrait victory screen.
    if (isPortrait) emitPortrait(fighters);
  }

  const allDisplayObjects = [gfx, titleText, ...rows.flatMap(r => [r.rank, r.name, r.right]), ...shimmers];

  return {
    ...makeMethods(fighters, scene, render),
    hide() {
      for (const o of allDisplayObjects) o.setVisible(false);
      for (const f of fires) f.hide();
    },
    show() {
      for (const o of allDisplayObjects) o.setVisible(true);
      render();
    },
    destroy() {
      if (fireUpdateHandler) scene.events.off('update', fireUpdateHandler);
    },
  };
}

// Per-rank fire config: rank 0 = tallest/brightest, rank 2 = shortest/dimmest.
// tickEvery slows the simulation (2 = 30fps, 3 = 20fps) so flames feel natural.
const FIRE_TIERS = [
  { dh: 30, seedHeat: 255, tickEvery: 1, palette: [
    { minH: 1,   color: 0xcc1100, alpha: 0.60 },
    { minH: 64,  color: 0xff4400, alpha: 0.85 },
    { minH: 128, color: 0xff9900, alpha: 0.95 },
    { minH: 192, color: 0xffee00, alpha: 1.00 },
  ]},
  { dh: 22, seedHeat: 190, tickEvery: 1, palette: [
    { minH: 1,   color: 0x991100, alpha: 0.50 },
    { minH: 64,  color: 0xee3300, alpha: 0.75 },
    { minH: 128, color: 0xff8800, alpha: 0.88 },
    { minH: 192, color: 0xffcc00, alpha: 0.95 },
  ]},
  { dh: 14, seedHeat: 130, tickEvery: 2, palette: [
    { minH: 1,   color: 0x661100, alpha: 0.40 },
    { minH: 50,  color: 0xcc2200, alpha: 0.62 },
    { minH: 95,  color: 0xee5500, alpha: 0.76 },
    { minH: 130, color: 0xff8800, alpha: 0.86 },
  ]},
];

// DOOM fire algorithm rendered via Phaser Graphics (no texture upload needed).
// Pixel buffer spread upward each frame; drawn with 4 colour buckets per frame.
function createDoomFire(scene, nameX, baseY, rank) {
  const cfg = FIRE_TIERS[rank] ?? FIRE_TIERS[0];
  const S   = 2;    // each fire "pixel" is S×S display pixels
  const DW  = 90;   // max display width
  const fw  = DW / S;
  const fh  = (cfg.dh / S) | 0;

  const buf = new Uint8Array(fw * fh);
  let frame = 0;

  const gfx = scene.add.graphics()
    .setDepth(3.7)  // behind text (depth 4) so names stay readable
    .setBlendMode(Phaser.BlendModes.ADD)
    .setVisible(false);

  function seed(nameW) {
    buf.fill(0);
    const cols     = Math.min(fw, Math.ceil(nameW / S));
    const fadeZone = Math.min(5, Math.floor(cols * 0.25));  // corner fade width
    for (let c = 0; c < cols; c++) {
      const edgeDist = Math.min(c, cols - 1 - c);
      // quarter-circle profile: sqrt gives rounded taper vs hard edge
      const t = edgeDist < fadeZone ? Math.sqrt(edgeDist / fadeZone) : 1;
      buf[(fh - 1) * fw + c] = Math.round(cfg.seedHeat * t);
    }
  }

  return {
    active: false,

    show(nameW) {
      seed(nameW);
      gfx.setVisible(true);
      this.active = true;
    },

    hide() {
      gfx.clear();
      gfx.setVisible(false);
      this.active = false;
    },

    tick() {
      frame++;
      if (frame % cfg.tickEvery !== 0) return;
      if (Math.random() > 0.4) return;

      for (let y = 0; y < fh - 1; y++) {
        for (let x = 0; x < fw; x++) {
          const heat  = buf[(y + 1) * fw + x];
          const decay = 2 + ((Math.random() * 40) | 0);
          const drift = ((Math.random() * 2) | 0);
          const dx    = x + drift;
          if (dx < fw) {
            buf[y * fw + dx] = Math.max(0, heat - decay);
          }
        }
      }

      gfx.clear();
      const pal = cfg.palette;
      for (let p = 0; p < pal.length; p++) {
        const minH = pal[p].minH;
        const maxH = p + 1 < pal.length ? pal[p + 1].minH : 256;
        gfx.fillStyle(pal[p].color, pal[p].alpha);
        for (let i = 0; i < fw * fh; i++) {
          const heat = buf[i];
          if (heat < minH || heat >= maxH) continue;
          gfx.fillRect(
            nameX + (i % fw) * S,
            baseY - (fh - (i / fw | 0)) * S,
            S, S
          );
        }
      }
    },
  };
}


function emitPortrait(fighters) {
  bus.emit('leaderboard-updated', getRankedArray(fighters).slice(0, 5).map(([userId, dmg, handle]) => ({
    userId,
    handle,
    damage: dmg,
  })));
}

export function makeMethods(fighters, scene, render) {
  return {
    seed(entries) {
      fighters.clear();
      for (const entry of entries ?? []) {
        if (entry.damage > 0) {
          fighters.set(entry.userId, { damage: entry.damage, handle: entry.handle ?? '' });
        }
      }
      render();
    },
    onHit(userId, damage, handle) {
      if (damage <= 0) return;
      const ex = fighters.get(userId);
      fighters.set(userId, { damage: (ex?.damage ?? 0) + damage, handle: handle || ex?.handle || '' });
      render();
    },
    reset() { fighters.clear(); render(); },
    damageFor(userId) {
      return fighters.get(userId)?.damage ?? 0;
    },
    rankOf(userId) {
      if (!fighters.has(userId)) {
        return null;
      }
      const index = ranked(fighters).findIndex(([id]) => id === userId);
      return index === -1 ? null : index + 1;
    },
    getRanked: () => getRankedArray(fighters),
  };
}

function ranked(fighters) {
  return [...fighters.entries()].sort((a, b) => b[1].damage - a[1].damage);
}

function getRankedArray(fighters) {
  return ranked(fighters).map(([userId, entry]) => [userId, entry.damage, entry.handle || `#${userId}`]);
}

function resolveHandle(scene, userId, stored) {
  return stored || scene.fighters.get(userId)?.handleText || `#${userId}`;
}

function fitHandle(handle) {
  if (handle.length > HANDLE_MAX) return handle.slice(0, HANDLE_MAX - 1) + '…';
  return handle;
}

function abbreviateDamage(n) {
  if (n >= 1e6) {
    const v = n / 1e6;
    return (v >= 10 ? Math.round(v) : v.toFixed(1)) + 'M';
  }
  if (n >= 1e3) return Math.round(n / 1e3) + 'K';
  return String(n);
}

export function showMvpCard(scene, { bossLabel, ranked, killerHandle }) {
  if (scene.mode === 'portrait') {
    bus.emit('show-mvp-overlay', {
      bossLabel,
      killerHandle,
      ranked: ranked.map(([userId, damage, handle]) => ({
        userId,
        handle: handle || `#${userId}`,
        damage,
      })),
    });
    return;
  }

  const cardX = scene.layout.logicalWidth / 2;
  const cardY = 160;
  const cardW = 440;
  const cardH = 120;
  const mvp = ranked[0];
  const runnerUp = ranked[1];

  const bg = scene.add
    .rectangle(cardX, cardY, cardW, cardH, 0x0f172a, 0.96)
    .setOrigin(0.5)
    .setStrokeStyle(2, 0xfbbf24, 1)
    .setDepth(200)
    .setAlpha(0);

  const title = scene.addSharpText(cardX, cardY - 42, `${bossLabel} DEFEATED`, {
    fontFamily: 'monospace',
    fontSize: '18px',
    color: '#fbbf24',
  });
  title.setDepth(202).setAlpha(0);

  const mvpLine = scene.addSharpText(
    cardX,
    cardY - 12,
    mvp ? `1st  ${mvpLabel(scene, mvp)}` : 'no damage logged',
    {
      fontFamily: 'monospace',
      fontSize: '16px',
      color: '#fde68a',
    },
  );
  mvpLine.setDepth(202).setAlpha(0);

  const runnerLine = scene.addSharpText(
    cardX,
    cardY + 20,
    runnerUp ? `2nd  ${mvpLabel(scene, runnerUp)}` : '',
    {
      fontFamily: 'monospace',
      fontSize: '13px',
      color: '#cbd5e1',
    },
  );
  runnerLine.setDepth(202).setAlpha(0);

  const killerLine = scene.addSharpText(
    cardX,
    cardY + 44,
    killerHandle ? `killing blow: ${killerHandle}` : '',
    {
      fontFamily: 'monospace',
      fontSize: '11px',
      color: '#94a3b8',
    },
  );
  killerLine.setDepth(202).setAlpha(0);

  const items = [bg, title, mvpLine, runnerLine, killerLine];
  scene.tweens.add({
    targets: items,
    alpha: 1,
    duration: 200,
    ease: 'Quad.easeOut',
  });
  scene.time.delayedCall(2500, () => {
    scene.tweens.add({
      targets: items,
      alpha: 0,
      duration: 400,
      ease: 'Quad.easeIn',
      onComplete: () => items.forEach(i => i.destroy()),
    });
  });
}

function mvpLabel(scene, [userId, total, handle]) {
  return `${handle || `#${userId}`}  —  ${total.toLocaleString()} dmg`;
}
