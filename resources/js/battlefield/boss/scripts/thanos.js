// ThaNode's script: six Infinity Stone sockets under the HP bar that fill one
// per stone-clock tick the boss survives. The count is never stored on the
// client — it is re-derived from {stones, stoneSchedule} (server-issued, see
// StoneClock) on every tick. That pair lives at bossState.script, the generic slot the engine
// keeps for any script and snapshotState() copies as a block, so an
// orientation reboot or a sleeping tab lands on the right number.
import { advanceStones, STONE_COLORS, STONE_MAX } from './thanos-stones.js';

const TICK_MS = 60_000;
const DEPTH = 3; // same layer as the HP text, under the boss sprite (5)

export const thanosScript = {
  /**
   * Translates a flat snake_case BossSpawned payload into this script's own
   * state object, which the engine stores at bossState.script.
   *
   * @param {{stones?: number, stone_schedule?: string}} payload
   * @return {{stones: number, stoneSchedule: string}}
   */
  readState(payload) {
    return { stones: payload.stones ?? 0, stoneSchedule: payload.stone_schedule ?? '' };
  },

  /**
   * Builds the sockets and starts the minute ticker.
   *
   * @param {Phaser.Scene} scene
   * @param {{script?: {stones?: number, stoneSchedule?: string|Array<number>}}} bossState The engine's live boss state; tick() writes the advanced stone state back to bossState.script so snapshotState() sees it.
   * @return {{sockets: Array<{socket: Phaser.GameObjects.Arc, gem: Phaser.GameObjects.Arc}>, ticker: Phaser.Time.TimerEvent}}
   */
  create(scene, bossState) {
    const L = scene.layout.stones;
    const sockets = [];
    for (let i = 0; i < STONE_MAX; i++) {
      const x = L.x + (i - (STONE_MAX - 1) / 2) * L.gap;
      const socket = scene.add.circle(x, L.y, L.radius, 0x0f172a, 1)
        .setStrokeStyle(1, 0x475569, 1)
        .setDepth(DEPTH);
      const gem = scene.add.circle(x, L.y, L.radius - 2, STONE_COLORS[i], 1)
        .setDepth(DEPTH)
        .setVisible(false);
      sockets.push({ socket, gem });
    }
    const handle = { sockets, ticker: null };
    handle.ticker = scene.time.addEvent({
      delay: TICK_MS,
      loop: true,
      callback: () => this.tick(scene, bossState, handle),
    });
    this.tick(scene, bossState, handle, { silent: true });
    return handle;
  },

  /**
   * Advances the stone state to now and reveals any newly earned gems.
   *
   * @param {Phaser.Scene} scene
   * @param {{script?: {stones?: number, stoneSchedule?: string|Array<number>}}} bossState
   * @param {{sockets: Array<{socket: object, gem: object}>}} handle
   * @param {{silent?: boolean}} [opts] silent skips the reveal pop (initial paint).
   * @return {void}
   */
  tick(scene, bossState, handle, opts = {}) {
    const next = advanceStones(bossState.script ?? {}, Date.now());
    bossState.script = next;

    handle.sockets.forEach(({ gem }, i) => {
      const earned = i < next.stones;
      if (earned && !gem.visible && !opts.silent) {
        gem.setVisible(true).setScale(0);
        scene.tweens.add({ targets: gem, scale: 1, duration: 500, ease: 'Back.easeOut' });
      } else {
        gem.setVisible(earned);
      }
    });
  },

  /**
   * Releases the ticker and every socket — called on boss death and scene shutdown.
   *
   * @param {Phaser.Scene} scene
   * @param {{sockets: Array<{socket: object, gem: object}>, ticker: object|null}} handle
   * @return {void}
   */
  destroy(scene, handle) {
    handle?.ticker?.remove(false);
    for (const { socket, gem } of handle?.sockets ?? []) {
      scene.tweens.killTweensOf(gem);
      socket.destroy();
      gem.destroy();
    }
  },
};
