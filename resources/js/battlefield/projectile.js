import { TIMINGS } from './config.js';

export function spawnProjectile(scene, fromX, fromY, type, damage, maxHp, onImpact, dmgScale = 1) {
  switch (type) {
    case 'slash':    return spawnSlash(scene, fromX, fromY, dmgScale, onImpact);
    case 'shuriken': return spawnShuriken(scene, fromX, fromY, dmgScale, onImpact);
    case 'arrow':    return spawnArrow(scene, fromX, fromY, dmgScale, onImpact);
    case 'blade':    return spawnBlade(scene, fromX, fromY, dmgScale, onImpact);
    default:         return spawnBlast(scene, fromX, fromY, dmgScale, onImpact);
  }
}

// ── Slash (Knight) — blue-white crescent ────────────────────────────────────

function ensureSlashTexture(scene) {
  if (scene.textures.exists('proj-slash')) return;
  const w = 56, h = 20;
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  const cy = h / 2;
  ctx.shadowColor = '#93c5fd'; ctx.shadowBlur = 8;
  const grad = ctx.createLinearGradient(0, 0, w, 0);
  grad.addColorStop(0, 'rgba(147,197,253,0)');
  grad.addColorStop(0.3, '#ffffff');
  grad.addColorStop(0.6, '#93c5fd');
  grad.addColorStop(1, '#1e3a8a');
  ctx.fillStyle = grad;
  ctx.beginPath();
  ctx.moveTo(w - 2, cy);
  ctx.quadraticCurveTo(w * 0.6, cy - 7, w * 0.1, cy - 2);
  ctx.lineTo(0, cy);
  ctx.quadraticCurveTo(w * 0.6, cy + 7, w - 2, cy);
  ctx.closePath();
  ctx.fill();
  scene.textures.addCanvas('proj-slash', canvas);
}

function spawnSlash(scene, fromX, fromY, dmgScale, onImpact) {
  ensureSlashTexture(scene);
  const toX     = scene.layout.boss.anchor.x;
  const toY     = scene.layout.boss.anchor.y;
  const sc      = dmgScale * 2.0;
  const lift    = 60;
  const dur     = TIMINGS.projectileArcMs * 0.7;
  const flyLeft = fromX > toX;
  const proj    = scene.add.image(fromX, fromY, 'proj-slash').setScale(sc).setDepth(10);
  if (flyLeft) proj.setFlipX(true);
  const trail = scene.add.particles(fromX, fromY, 'spark', {
    tint:      { onEmit: () => Phaser.Math.RND.pick([0x93c5fd, 0xbfdbfe, 0x60a5fa, 0xffffff]) },
    scale:     { start: sc * 0.38, end: 0 },
    alpha:     { start: 0.52, end: 0 },
    speedX:    { min: -18, max: 18 },
    speedY:    { min: -18, max: 18 },
    lifespan:  { min: 75, max: 150 },
    frequency: 18,
    quantity:  1,
    blendMode: Phaser.BlendModes.ADD,
  }).setDepth(9);
  const state   = { t: 0 };
  scene.tweens.add({
    targets: state, t: 1, duration: dur, ease: 'Power2.easeIn',
    onUpdate: () => {
      const t = state.t;
      const x = fromX + (toX - fromX) * t;
      const y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      proj.setPosition(x, y);
      trail.setPosition(x, y);
      const dy = (toY - fromY) - Math.cos(t * Math.PI) * lift * Math.PI;
      proj.rotation = Math.atan2(dy, Math.abs(toX - fromX)) * (flyLeft ? -1 : 1);
    },
    onComplete: () => {
      proj.destroy();
      trail.stop();
      scene.time.delayedCall(200, () => { if (trail.scene) trail.destroy(); });
      onImpact?.();
    },
  });
}

// ── Blast (Redhat) — purple fireball ────────────────────────────────────────

function spawnBlast(scene, fromX, fromY, dmgScale, onImpact) {
  const sprite = scene.add.sprite(fromX, fromY, 'fireball').setScale(4).setTint(0xc026d3).setDepth(10);
  if (!scene.anims.exists('fireball-loop')) {
    scene.anims.create({
      key: 'fireball-loop',
      frames: scene.anims.generateFrameNumbers('fireball', { start: 0, end: 3 }),
      frameRate: 16,
      repeat: -1,
    });
  }
  sprite.play('fireball-loop');
  const lift  = 55;
  const toX   = scene.layout.boss.anchor.x;
  const toY   = scene.layout.boss.anchor.y;
  const trail = scene.add.particles(fromX, fromY, 'spark', {
    tint:      { onEmit: () => Phaser.Math.RND.pick([0xc026d3, 0x7c3aed, 0xe879f9, 0xfb923c, 0xffffff]) },
    scale:     { start: 2.8, end: 0 },
    alpha:     { start: 0.55, end: 0 },
    speedX:    { min: -28, max: 28 },
    speedY:    { min: -28, max: 28 },
    lifespan:  { min: 90, max: 190 },
    frequency: 16,
    quantity:  2,
    blendMode: Phaser.BlendModes.ADD,
  }).setDepth(9);
  scene.tweens.add({
    targets: sprite, x: toX, y: toY,
    duration: TIMINGS.projectileArcMs, ease: 'Sine.easeIn',
    onUpdate: tween => {
      const t = tween.progress;
      sprite.y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      sprite.rotation += 0.2;
      trail.setPosition(sprite.x, sprite.y);
    },
    onComplete: () => {
      sprite.destroy();
      trail.stop();
      scene.time.delayedCall(220, () => { if (trail.scene) trail.destroy(); });
      onImpact?.();
    },
  });
}

// ── Shuriken (Ninjagirl) — spinning pink star ────────────────────────────────

function ensureShurikenTexture(scene) {
  if (scene.textures.exists('proj-shuriken')) return;
  const size = 24;
  const canvas = document.createElement('canvas');
  canvas.width = size; canvas.height = size;
  const ctx = canvas.getContext('2d');
  const cx = size / 2, cy = size / 2;
  ctx.shadowColor = '#e879f9'; ctx.shadowBlur = 10;
  ctx.fillStyle = '#f0abfc';
  ctx.save();
  ctx.translate(cx, cy);
  ctx.beginPath();
  ctx.moveTo(0, -cy + 1);
  ctx.lineTo(3, -3);
  ctx.lineTo(cx - 1, 0);
  ctx.lineTo(3, 3);
  ctx.lineTo(0, cy - 1);
  ctx.lineTo(-3, 3);
  ctx.lineTo(-cx + 1, 0);
  ctx.lineTo(-3, -3);
  ctx.closePath();
  ctx.fill();
  ctx.restore();
  scene.textures.addCanvas('proj-shuriken', canvas);
}

function spawnShuriken(scene, fromX, fromY, dmgScale, onImpact) {
  ensureShurikenTexture(scene);
  const toX   = scene.layout.boss.anchor.x;
  const toY   = scene.layout.boss.anchor.y;
  const sc    = dmgScale * 1.8;
  const lift  = 40;
  const dur   = TIMINGS.projectileArcMs * 0.6;
  const proj  = scene.add.image(fromX, fromY, 'proj-shuriken').setScale(sc).setDepth(10);
  const trail = scene.add.particles(fromX, fromY, 'spark', {
    tint:      { onEmit: () => Phaser.Math.RND.pick([0xe879f9, 0xf0abfc, 0xd946ef, 0xfdf4ff]) },
    scale:     { start: sc * 0.55, end: 0 },
    alpha:     { start: 0.58, end: 0 },
    speedX:    { min: -22, max: 22 },
    speedY:    { min: -22, max: 22 },
    lifespan:  { min: 70, max: 140 },
    frequency: 20,
    quantity:  1,
    blendMode: Phaser.BlendModes.ADD,
  }).setDepth(9);
  const state = { t: 0 };
  scene.tweens.add({
    targets: state, t: 1, duration: dur, ease: 'Power2.easeIn',
    onUpdate: () => {
      const t = state.t;
      const x = fromX + (toX - fromX) * t;
      const y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      proj.setPosition(x, y);
      trail.setPosition(x, y);
      proj.rotation += 0.18;
    },
    onComplete: () => {
      proj.destroy();
      trail.stop();
      scene.time.delayedCall(180, () => { if (trail.scene) trail.destroy(); });
      onImpact?.();
    },
  });
}

// ── Arrow (Adventurer) — golden arrow ───────────────────────────────────────

function ensureArrowTexture(scene) {
  if (scene.textures.exists('proj-arrow')) return;
  const w = 52, h = 12;
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  const cy = h / 2;
  ctx.shadowColor = '#fbbf24'; ctx.shadowBlur = 8;
  const shaftGrad = ctx.createLinearGradient(0, 0, w, 0);
  shaftGrad.addColorStop(0, 'rgba(251,191,36,0)');
  shaftGrad.addColorStop(0.2, '#fde68a');
  shaftGrad.addColorStop(0.8, '#fbbf24');
  shaftGrad.addColorStop(1, '#92400e');
  ctx.strokeStyle = shaftGrad; ctx.lineWidth = 2.5;
  ctx.beginPath(); ctx.moveTo(2, cy); ctx.lineTo(w - 6, cy); ctx.stroke();
  ctx.fillStyle = '#fef3c7';
  ctx.beginPath();
  ctx.moveTo(w, cy); ctx.lineTo(w - 7, cy - 4); ctx.lineTo(w - 5, cy); ctx.lineTo(w - 7, cy + 4);
  ctx.closePath(); ctx.fill();
  scene.textures.addCanvas('proj-arrow', canvas);
}

function spawnArrow(scene, fromX, fromY, dmgScale, onImpact) {
  ensureArrowTexture(scene);
  const toX     = scene.layout.boss.anchor.x;
  const toY     = scene.layout.boss.anchor.y;
  const sc      = dmgScale * 2.0;
  const lift    = 80;
  const dur     = TIMINGS.projectileArcMs * 0.65;
  const flyLeft = fromX > toX;
  const proj    = scene.add.image(fromX, fromY, 'proj-arrow').setScale(sc).setDepth(10);
  if (flyLeft) proj.setFlipX(true);
  const trail = scene.add.particles(fromX, fromY, 'spark', {
    tint:      { onEmit: () => Phaser.Math.RND.pick([0xfbbf24, 0xfde68a, 0x86efac, 0xfef9c3]) },
    scale:     { start: sc * 0.35, end: 0 },
    alpha:     { start: 0.55, end: 0 },
    speedX:    { min: -16, max: 16 },
    speedY:    { min: -16, max: 16 },
    lifespan:  { min: 80, max: 160 },
    frequency: 18,
    quantity:  1,
    blendMode: Phaser.BlendModes.ADD,
  }).setDepth(9);
  const state   = { t: 0 };
  scene.tweens.add({
    targets: state, t: 1, duration: dur, ease: 'Power2.easeIn',
    onUpdate: () => {
      const t = state.t;
      const x = fromX + (toX - fromX) * t;
      const y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      proj.setPosition(x, y);
      trail.setPosition(x, y);
      const dy = (toY - fromY) - Math.cos(t * Math.PI) * lift * Math.PI;
      proj.rotation = Math.atan2(dy, Math.abs(toX - fromX)) * (flyLeft ? -1 : 1);
    },
    onComplete: () => {
      proj.destroy();
      trail.stop();
      scene.time.delayedCall(200, () => { if (trail.scene) trail.destroy(); });
      onImpact?.();
    },
  });
}

// ── Blade (Shinobi) — dark purple kunai with particle trail ─────────────────

function ensureBladeTexture(scene) {
  if (scene.textures.exists('proj-blade')) return;
  const w = 88, h = 20;
  const canvas = document.createElement('canvas');
  canvas.width = w; canvas.height = h;
  const ctx = canvas.getContext('2d');
  const cy = h / 2;
  ctx.shadowColor = '#a855f7'; ctx.shadowBlur = 14;
  const grad = ctx.createLinearGradient(0, cy - 4, 0, cy + 4);
  grad.addColorStop(0, '#f0abfc');
  grad.addColorStop(0.4, '#7c3aed');
  grad.addColorStop(1, '#1a0030');
  ctx.fillStyle = grad;
  ctx.beginPath();
  ctx.moveTo(w - 2, cy);
  ctx.lineTo(w * 0.7, cy - 7); ctx.lineTo(w * 0.1, cy - 3);
  ctx.lineTo(w * 0.05, cy);
  ctx.lineTo(w * 0.1, cy + 3); ctx.lineTo(w * 0.7, cy + 7);
  ctx.closePath(); ctx.fill();
  ctx.shadowBlur = 0; ctx.strokeStyle = '#e879f9'; ctx.lineWidth = 1.5;
  ctx.beginPath(); ctx.moveTo(w - 2, cy); ctx.lineTo(w * 0.1, cy - 3); ctx.stroke();
  scene.textures.addCanvas('proj-blade', canvas);
}

function spawnBlade(scene, fromX, fromY, dmgScale, onImpact) {
  ensureBladeTexture(scene);
  const toX     = scene.layout.boss.anchor.x;
  const toY     = scene.layout.boss.anchor.y;
  const sc      = dmgScale * 1.4;
  const lift    = 36;
  const dur     = TIMINGS.projectileArcMs * 0.65;
  const flyLeft = fromX > toX;
  const blade   = scene.add.image(fromX, fromY, 'proj-blade').setScale(sc).setDepth(10);
  if (flyLeft) blade.setFlipX(true);

  const trail = scene.add.particles(fromX, fromY, 'spark', {
    tint: { onEmit: () => Phaser.Math.RND.pick([0xa855f7, 0x7c3aed, 0xf0abfc, 0x4c1d95]) },
    scale: { start: sc * 1.5, end: 0 },
    alpha: { start: 0.55, end: 0 },
    speedX: { min: -15, max: 15 },
    speedY: { min: -15, max: 15 },
    lifespan: { min: 100, max: 200 },
    frequency: 12, quantity: 2,
    blendMode: Phaser.BlendModes.ADD,
  });

  const state = { t: 0 };
  scene.tweens.add({
    targets: state, t: 1, duration: dur, ease: 'Power2.easeIn',
    onUpdate: () => {
      const t = state.t;
      const x = fromX + (toX - fromX) * t;
      const y = fromY + (toY - fromY) * t - Math.sin(t * Math.PI) * lift;
      blade.setPosition(x, y);
      trail.setPosition(x, y);
      const dy = (toY - fromY) - Math.cos(t * Math.PI) * lift * Math.PI;
      blade.rotation = Math.atan2(dy, Math.abs(toX - fromX)) * (flyLeft ? -1 : 1);
    },
    onComplete: () => {
      trail.stop();
      blade.destroy();
      scene.time.delayedCall(300, () => { if (trail.scene) trail.destroy(); });
      onImpact?.();
    },
  });
}
