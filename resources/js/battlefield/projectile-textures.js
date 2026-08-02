/** Ensures the slash (crescent) projectile texture exists in the scene. */
export function ensureSlashTexture(scene) {
  if (scene.textures.exists('proj-slash')) { return; }
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

/** Ensures the spinning shuriken projectile texture exists in the scene. */
export function ensureShurikenTexture(scene) {
  if (scene.textures.exists('proj-shuriken')) { return; }
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

/** Ensures the golden arrow projectile texture exists in the scene. */
export function ensureArrowTexture(scene) {
  if (scene.textures.exists('proj-arrow')) { return; }
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

/** Ensures the dark purple kunai (blade) projectile texture exists in the scene. */
export function ensureBladeTexture(scene) {
  if (scene.textures.exists('proj-blade')) { return; }
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
