import Phaser from 'phaser';

// Per-rank fire config: rank 0 = tallest/brightest, rank 2 = shortest/dimmest.
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

/**
 * Creates a DOOM-fire effect rendered via Phaser Graphics (no texture upload needed).
 *
 * @param {Phaser.Scene} scene
 * @param {number} nameX
 * @param {number} baseY
 * @param {number} rank
 * @return {{ active: boolean, show: Function, hide: Function, tick: Function }}
 */
export function createDoomFire(scene, nameX, baseY, rank) {
  const cfg = FIRE_TIERS[rank] ?? FIRE_TIERS[0];
  const S   = 2;
  const DW  = 90;
  const fw  = DW / S;
  const fh  = (cfg.dh / S) | 0;

  const buf = new Uint8Array(fw * fh);
  let frame = 0;

  const gfx = scene.add.graphics()
    .setDepth(3.7)
    .setBlendMode(Phaser.BlendModes.ADD)
    .setVisible(false);

  function seed(nameW) {
    buf.fill(0);
    const cols     = Math.min(fw, Math.ceil(nameW / S));
    const fadeZone = Math.min(5, Math.floor(cols * 0.25));
    for (let c = 0; c < cols; c++) {
      const edgeDist = Math.min(c, cols - 1 - c);
      const t = edgeDist < fadeZone ? Math.sqrt(edgeDist / fadeZone) : 1;
      buf[(fh - 1) * fw + c] = Math.round(cfg.seedHeat * t);
    }
  }

  return {
    active: false,
    show(nameW) { seed(nameW); gfx.setVisible(true); this.active = true; },
    hide() { gfx.clear(); gfx.setVisible(false); this.active = false; },
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
          if (dx < fw) buf[y * fw + dx] = Math.max(0, heat - decay);
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
          gfx.fillRect(nameX + (i % fw) * S, baseY - (fh - (i / fw | 0)) * S, S, S);
        }
      }
    },
  };
}
