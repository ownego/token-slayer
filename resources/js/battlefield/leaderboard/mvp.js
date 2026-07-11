import { bus } from '../bus.js';

/**
 * Displays the post-kill MVP card overlay.
 *
 * @param {Phaser.Scene} scene
 * @param {{ bossLabel: string, ranked: Array, killerHandle: string|null }} opts
 * @return {void}
 */
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
  const mvp      = ranked[0];
  const runnerUp = ranked[1];

  const bg = scene.add
    .rectangle(cardX, cardY, cardW, cardH, 0x0f172a, 0.96)
    .setOrigin(0.5)
    .setStrokeStyle(2, 0xfbbf24, 1)
    .setDepth(200)
    .setAlpha(0);

  const title = scene.addSharpText(cardX, cardY - 42, `${bossLabel} DEFEATED`, {
    fontFamily: 'monospace', fontSize: '18px', color: '#fbbf24',
  });
  title.setDepth(202).setAlpha(0);

  const mvpLine = scene.addSharpText(
    cardX, cardY - 12,
    mvp ? `1st  ${_mvpLabel(mvp)}` : 'no damage logged',
    { fontFamily: 'monospace', fontSize: '16px', color: '#fde68a' },
  );
  mvpLine.setDepth(202).setAlpha(0);

  const runnerLine = scene.addSharpText(
    cardX, cardY + 20,
    runnerUp ? `2nd  ${_mvpLabel(runnerUp)}` : '',
    { fontFamily: 'monospace', fontSize: '13px', color: '#cbd5e1' },
  );
  runnerLine.setDepth(202).setAlpha(0);

  const killerLine = scene.addSharpText(
    cardX, cardY + 44,
    killerHandle ? `killing blow: ${killerHandle}` : '',
    { fontFamily: 'monospace', fontSize: '11px', color: '#94a3b8' },
  );
  killerLine.setDepth(202).setAlpha(0);

  const items = [bg, title, mvpLine, runnerLine, killerLine];
  scene.tweens.add({ targets: items, alpha: 1, duration: 200, ease: 'Quad.easeOut' });
  scene.time.delayedCall(2500, () => {
    scene.tweens.add({
      targets: items, alpha: 0, duration: 400, ease: 'Quad.easeIn',
      onComplete: () => items.forEach(i => i.destroy()),
    });
  });
}

/**
 * Formats a [userId, total, handle] tuple into a display label.
 *
 * @param {[number, number, string]} entry
 * @return {string}
 */
function _mvpLabel([userId, total, handle]) {
  return `${handle || `#${userId}`}  —  ${total.toLocaleString()} dmg`;
}
