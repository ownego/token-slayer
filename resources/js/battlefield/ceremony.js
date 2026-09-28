/**
 * The kill ceremony: slow-mo + shake, the killer's card, then a 3-2-1
 * countdown before the next boss appears — replaces the old Phaser MVP
 * card. Ported from the mockup's `card()`/`countdown()` slam sequence.
 */

/**
 * Timings for the kill ceremony; reduced motion keeps the information, drops the motion.
 *
 * @param {{reduced:boolean}} opts
 * @return {{slowMs:number, timeScale:number, cardMs:number, countdown:number[], stepMs:number}}
 */
export function ceremonyTimeline({ reduced }) {
  return { slowMs: reduced ? 0 : 550, timeScale: reduced ? 1 : 0.25, cardMs: 1700, countdown: [3, 2, 1], stepMs: 900 };
}

/**
 * Slow-mo + shake, the killer's card, then 3-2-1. Time scales always come back.
 *
 * @param {object} scene the battlefield scene (tweens, anims, cameras, showKillCard, countdown, wait)
 * @param {{killer:object, bossName:string, bossNumber:number}} kill
 * @return {Promise<void>}
 */
export async function runCeremony(scene, kill) {
  const t = ceremonyTimeline({ reduced: globalThis.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false });
  try {
    if (t.slowMs) {
      scene.tweens.timeScale = t.timeScale;
      scene.anims.globalTimeScale = t.timeScale;
      scene.cameras.main.shake(500, 0.012);
      scene.cameras.main.flash(180, 255, 240, 200);
      await scene.wait(t.slowMs);
    }
    scene.tweens.timeScale = 1;
    scene.anims.globalTimeScale = 1;
    await scene.showKillCard(kill, t.cardMs);
    for (const n of t.countdown) { await scene.countdown(n, t.stepMs); }
  } finally {
    scene.tweens.timeScale = 1;
    scene.anims.globalTimeScale = 1;
  }
}

/**
 * Holds a boss spawn while the kill ceremony runs, so the 3-2-1 counts down to
 * the new boss instead of over it.
 *
 * @return {{hold: function(Function): void, open: function(): void, busy: boolean}}
 */
export function createSpawnGate() {
  const waiting = [];
  return {
    busy: false,
    hold(fn) { if (this.busy) { waiting.push(fn); } else { fn(); } },
    open() { this.busy = false; waiting.splice(0).forEach(fn => fn()); },
  };
}
