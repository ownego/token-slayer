// ThaNode's script: six Infinity Stone sockets under the boss plate's HP bar
// (hud-plate.blade.php's .bf-plate — the canvas HP bar is gone), filled one per
// stone-clock tick the boss survives. The count is never stored on the client:
// it is re-derived from {stones, stoneSchedule} (server-issued, see StoneClock)
// at bossState.script, the generic slot snapshotState() copies as a block, so
// an orientation restart or a sleeping tab lands on the right number.
//
// A stone the viewer has not watched arrive yet (stone-seen.js) stays dark
// until the tab is visible, then plays its own animation over the boss
// (stone-effects.js), flies up into its socket on the plate (stone-flight.js)
// and lights it — so a viewer returning to the tab sees each stone that landed
// while they were away, one after another. The sixth adds a gauntlet finale.
import { advanceStones, STONE_COLORS, STONE_MAX, STONE_NAMES } from './thanos-stones.js';
import { pageSeenStore, unseenOrdinals } from './stone-seen.js';
import { playGauntletComplete, playStoneEffect } from './stone-effects.js';
import { flyGem, pulseEdges, worldToScreen } from './stone-flight.js';

const TICK_MS = 60_000;
// Lets the HUD's own load intro (the plate sliding in, the HP bar filling) finish first.
const FIRST_REVEAL_MS = 1_400;
const BOSS_KEY = 'boss-thanos';

/**
 * Builds the socket row and appends it to the boss plate.
 *
 * @param {HTMLElement|null} plate
 * @return {{row: HTMLElement|null, gems: Array<HTMLElement>}}
 */
function buildSockets(plate) {
  if (!plate) {
    return { row: null, gems: [] };
  }
  const row = document.createElement('div');
  row.className = 'bf-stones';
  row.setAttribute('aria-label', 'Infinity Stones');
  const gems = STONE_NAMES.map((name, i) => {
    const gem = document.createElement('i');
    gem.className = 'stone';
    gem.title = `${name} Stone`;
    gem.style.setProperty('--stone', `#${STONE_COLORS[i].toString(16).padStart(6, '0')}`);
    row.appendChild(gem);
    return gem;
  });
  plate.appendChild(row);
  return { row, gems };
}

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
   * Builds the sockets, lights the stones this viewer has already seen, and
   * starts the minute ticker and the tab-visibility listener that reveal the rest.
   *
   * @param {Phaser.Scene} scene
   * @param {{number?: number, script?: {stones?: number, stoneSchedule?: string|Array<number>}}} bossState The engine's live boss state; tick() writes the advanced stone state back to bossState.script so snapshotState() sees it.
   * @return {object} The handle destroy() takes back.
   */
  create(scene, bossState) {
    const handle = {
      ...buildSockets(document.querySelector('.bf-plate')),
      seen: pageSeenStore(),
      live: new Set(),
      abort: new AbortController(),
      playing: false,
      disposed: false,
      ticker: null,
      firstReveal: null,
      onVisibility: null,
      onStorage: null,
    };
    this.tick(bossState);
    this.paint(bossState, handle);

    handle.ticker = scene.time.addEvent({
      delay: TICK_MS,
      loop: true,
      callback: () => {
        this.tick(bossState);
        this.paint(bossState, handle);
        this.reveal(scene, bossState, handle);
      },
    });
    handle.firstReveal = scene.time.delayedCall(FIRST_REVEAL_MS, () => this.reveal(scene, bossState, handle));
    // Phaser's clock stands still in a hidden tab, so coming back re-derives the
    // count straight away instead of waiting out the ticker.
    handle.onVisibility = () => {
      if (!document.hidden) {
        this.tick(bossState);
        this.paint(bossState, handle);
        this.reveal(scene, bossState, handle);
      }
    };
    document.addEventListener('visibilitychange', handle.onVisibility);
    // Another tab that watched a stone arrive has already recorded it as seen,
    // so this tab has nothing left to reveal; repaint to light it here too.
    handle.onStorage = () => this.paint(bossState, handle);
    window.addEventListener('storage', handle.onStorage);
    return handle;
  },

  /**
   * Advances the stone state to now.
   *
   * @param {{script?: {stones?: number, stoneSchedule?: string|Array<number>}}} bossState
   * @return {void}
   */
  tick(bossState) {
    bossState.script = advanceStones(bossState.script ?? {}, Date.now());
  },

  /**
   * Lights every socket the viewer has already seen arrive, without animation.
   *
   * @param {{number?: number, script?: {stones?: number}}} bossState
   * @param {{gems: Array<HTMLElement>, seen: {read: function(number): number}}} handle
   * @return {void}
   */
  paint(bossState, handle) {
    const lit = Math.min(bossState.script?.stones ?? 0, handle.seen.read(bossState.number ?? 0));
    handle.gems.forEach((gem, i) => gem.classList.toggle('on', i < lit));
    handle.row?.classList.toggle('full', lit >= STONE_MAX);
  },

  /**
   * Plays each unseen stone's animation in turn while the tab is visible,
   * lighting its socket and recording it as seen as each one ends.
   *
   * @param {Phaser.Scene} scene
   * @param {{number?: number, script?: {stones?: number}}} bossState
   * @param {object} handle
   * @return {Promise<void>}
   */
  async reveal(scene, bossState, handle) {
    if (handle.disposed || handle.playing || document.hidden) {
      return;
    }
    const bossNumber = bossState.number ?? 0;
    const pending = unseenOrdinals(handle.seen.read(bossNumber), Math.min(bossState.script?.stones ?? 0, STONE_MAX));
    if (pending.length === 0) {
      return;
    }
    handle.playing = true;
    try {
      for (const ordinal of pending) {
        if (handle.disposed) {
          return;
        }
        const gem = handle.gems[ordinal - 1];
        if (!scene.reducedMotion) {
          const color = gem?.style.getPropertyValue('--stone') || '#fff';
          pulseEdges(document.getElementById('bf-hud'), color);
          await playStoneEffect(scene, ordinal, this.anchor(scene), handle.live, handle.abort.signal);
          if (handle.disposed) {
            return;
          }
          await this.flyToSocket(scene, gem, color);
        }
        if (handle.disposed) {
          return;
        }
        handle.seen.write(bossNumber, ordinal);
        gem?.classList.add('on');
        if (!scene.reducedMotion) {
          // Reduced motion disables the pop animation, so its animationend would never clear the ring.
          gem?.classList.add('pop');
          gem?.addEventListener('animationend', () => gem.classList.remove('pop'), { once: true });
        }
        if (ordinal === STONE_MAX) {
          handle.row?.classList.add('full', 'complete');
          if (!scene.reducedMotion) {
            await playGauntletComplete(scene, this.anchor(scene), handle.live, handle.abort.signal);
          }
        }
      }
    } finally {
      handle.playing = false;
    }
    // a tick may have landed another stone mid-sequence
    this.reveal(scene, bossState, handle);
  },

  /**
   * Flies the gem from the boss on the canvas up into its socket on the plate.
   * No-op when there is no socket to land in (the plate isn't on the page).
   *
   * @param {Phaser.Scene} scene
   * @param {HTMLElement|undefined} gem The socket.
   * @param {string} color CSS colour.
   * @return {Promise<void>}
   */
  async flyToSocket(scene, gem, color) {
    const canvas = scene.game?.canvas;
    if (!gem || !canvas) {
      return;
    }
    const from = worldToScreen(this.anchor(scene), scene.cameras.main.worldView, canvas.getBoundingClientRect());
    const socket = gem.getBoundingClientRect();
    await flyGem(from, { x: socket.left + socket.width / 2, y: socket.top + socket.height / 2 }, color);
  },

  /**
   * Where a stone's animation plays: on ThaNode's sprite, or the layout's boss
   * anchor while that sprite is still loading in.
   *
   * @param {Phaser.Scene} scene
   * @return {{x: number, y: number}}
   */
  anchor(scene) {
    const sprite = scene.bossSprite;
    if (sprite?.active && sprite.getData?.('bossTypeKey') === BOSS_KEY) {
      return { x: sprite.x, y: sprite.y };
    }
    return { ...scene.layout.boss.anchor };
  },

  /**
   * Releases the ticker, the visibility and storage listeners, any animation mid-flight and
   * the socket row — called on boss death and scene shutdown.
   *
   * @param {Phaser.Scene} scene
   * @param {object} handle
   * @return {void}
   */
  destroy(scene, handle) {
    if (!handle) {
      return;
    }
    handle.disposed = true;
    handle.abort.abort();
    handle.ticker?.remove(false);
    handle.firstReveal?.remove(false);
    document.removeEventListener('visibilitychange', handle.onVisibility);
    window.removeEventListener('storage', handle.onStorage);
    for (const obj of handle.live) {
      scene.tweens.killTweensOf(obj);
      obj.destroy();
    }
    handle.live.clear();
    handle.row?.remove();
  },
};
