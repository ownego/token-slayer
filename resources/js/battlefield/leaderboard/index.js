import Phaser from 'phaser';
import { bus } from '../bus.js';
import { createDoomFire } from './doom-fire.js';
import { showMvpCard as _showMvpCard } from './mvp.js';

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
const RANK_W     = 27;

const RANK_COLORS  = ['#fbbf24', '#e2e8f0', '#f97316', '#94a3b8', '#64748b'];
const DMG_COLOR    = '#38bdf8';
const TITLE_COLOR  = '#fbbf24';
const BORDER_COLOR = 0xfbbf24;
const BG_COLOR     = 0x0b1629;

/** Renders the live TOP DAMAGE leaderboard panel and tracks per-fighter damage. */
export class Leaderboard {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene      = scene;
    this._fighters  = new Map();
    this._isPortrait = scene.mode === 'portrait';

    if (this._isPortrait) {
      this._rows = [];
      this._shimmers = [];
      this._fires = [];
      this._fireUpdateHandler = null;
      this._allDisplayObjects = [];
      return;
    }

    const W      = scene.layout.logicalWidth;
    const panL   = W - PANEL_PAD - PANEL_W;
    const panTopY = PANEL_TOP;

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
      fontFamily: 'monospace', fontSize: '16px', color: TITLE_COLOR,
      stroke: '#060d1f', strokeThickness: 4,
    }, 3).setOrigin(0, 0).setDepth(4);

    const rowsStartY = sepY + SEP_H + ROW_H / 2;
    const nameX      = panL + INNER_PAD + 2 + RANK_W;

    this._rows = Array.from({ length: TOP_ROWS }, (_, i) => {
      const y     = rowsStartY + i * ROW_H;
      const style = { fontFamily: 'monospace', fontSize: '15px', color: RANK_COLORS[i], stroke: '#060d1f', strokeThickness: 3 };
      const rank  = scene.addSharpText(panL + INNER_PAD + 2, y, '', style, 3).setOrigin(0, 0.5).setDepth(4);
      const name  = scene.addSharpText(nameX, y, '', style, 3).setOrigin(0, 0.5).setDepth(4);
      const right = scene.addSharpText(panL + PANEL_W - INNER_PAD, y, '', {
        fontFamily: 'monospace', fontSize: '15px', color: DMG_COLOR, stroke: '#060d1f', strokeThickness: 3,
      }, 3).setOrigin(1, 0.5).setDepth(4);
      return { rank, name, right };
    });

    const SHIMMER_COLORS = ['#ffcc00', '#ff8800', '#ff4400'];
    const SHIMMER_ALPHA  = [0.75, 0.55, 0.32];

    this._shimmers = this._rows.slice(0, 3).map((_, i) => {
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

    this._fires = this._rows.slice(0, 3).map((_, i) => {
      const baseY = rowsStartY + i * ROW_H + 7;
      return createDoomFire(scene, nameX, baseY, i);
    });

    this._fireUpdateHandler = () => {
      for (const f of this._fires) if (f.active) f.tick();
    };
    scene.events.on('update', this._fireUpdateHandler);

    this._allDisplayObjects = [gfx, titleText, ...this._rows.flatMap(r => [r.rank, r.name, r.right]), ...this._shimmers];
  }

  // ─── State methods ────────────────────────────────────────────────────────────

  /**
   * Seeds the leaderboard from an array of persisted entries.
   *
   * @param {Array<{userId: number, damage: number, handle: string}>} entries
   * @return {void}
   */
  seed(entries) {
    this._fighters.clear();
    for (const entry of entries ?? []) {
      if (entry.damage > 0) {
        this._fighters.set(entry.userId, { damage: entry.damage, handle: entry.handle ?? '' });
      }
    }
    this._render();
  }

  /**
   * Records damage dealt by a fighter and re-renders.
   *
   * @param {number} userId
   * @param {number} damage
   * @param {string} handle
   * @return {void}
   */
  onHit(userId, damage, handle) {
    if (damage <= 0) return;
    const ex = this._fighters.get(userId);
    this._fighters.set(userId, { damage: (ex?.damage ?? 0) + damage, handle: handle || ex?.handle || '' });
    this._render();
  }

  /**
   * Clears all accumulated damage and re-renders.
   *
   * @return {void}
   */
  reset() { this._fighters.clear(); this._render(); }

  /**
   * Returns the total damage dealt by a user, or 0 if unknown.
   *
   * @param {number} userId
   * @return {number}
   */
  damageFor(userId) { return this._fighters.get(userId)?.damage ?? 0; }

  /**
   * Returns the 1-based rank of a user, or null if not on the board.
   *
   * @param {number} userId
   * @return {number|null}
   */
  rankOf(userId) {
    if (!this._fighters.has(userId)) return null;
    const index = this._ranked().findIndex(([id]) => id === userId);
    return index === -1 ? null : index + 1;
  }

  /**
   * Returns all fighters sorted by damage as [userId, damage, handle] tuples.
   *
   * @return {Array<[number, number, string]>}
   */
  getRanked() { return this._getRankedArray(); }

  /**
   * Hides all leaderboard display objects and extinguishes DOOM fire.
   *
   * @return {void}
   */
  hide() {
    for (const o of this._allDisplayObjects) o.setVisible(false);
    for (const f of this._fires) f.hide();
  }

  /**
   * Shows all leaderboard display objects and re-renders the current rankings.
   *
   * @return {void}
   */
  show() {
    for (const o of this._allDisplayObjects) o.setVisible(true);
    this._render();
  }

  /**
   * Tears down the scene update listener for DOOM fire ticks.
   *
   * @return {void}
   */
  destroy() {
    if (this._fireUpdateHandler) this.scene.events.off('update', this._fireUpdateHandler);
  }

  // ─── Private helpers ──────────────────────────────────────────────────────────

  /**
   * Updates all leaderboard row texts from the current fighter rankings.
   *
   * @return {void}
   */
  _render() {
    if (this._isPortrait) {
      this._emitPortrait();
      return;
    }
    const top = this._ranked().slice(0, TOP_ROWS);
    for (let i = 0; i < TOP_ROWS; i++) {
      if (top[i]) {
        const [userId, entry] = top[i];
        const handle = this._fitHandle(this._resolveHandle(userId, entry.handle));
        this._rows[i].rank.setText(`${i + 1}.`);
        this._rows[i].name.setText(handle);
        this._rows[i].right.setText(Leaderboard.abbreviateDamage(entry.damage).padStart(DMG_W));
        if (i < 3) {
          this._shimmers[i].setText(handle).setVisible(true);
          this._fires[i].show(this._rows[i].name.width);
        }
      } else {
        this._rows[i].rank.setText('');
        this._rows[i].name.setText('');
        this._rows[i].right.setText('');
        if (i < 3) {
          this._shimmers[i].setText('').setVisible(false);
          this._fires[i].hide();
        }
      }
    }
  }

  /**
   * Returns all fighters sorted by damage descending.
   *
   * @return {Array<[number, object]>}
   */
  _ranked() {
    return [...this._fighters.entries()].sort((a, b) => b[1].damage - a[1].damage);
  }

  /**
   * Returns all fighters as [userId, damage, handle] tuples, sorted by damage descending.
   *
   * @return {Array<[number, number, string]>}
   */
  _getRankedArray() {
    return this._ranked().map(([userId, entry]) => [userId, entry.damage, entry.handle || `#${userId}`]);
  }

  /**
   * Returns the display handle for a user, falling back through live fighter state and ID.
   *
   * @param {number} userId
   * @param {string} stored
   * @return {string}
   */
  _resolveHandle(userId, stored) {
    return stored || this.scene.fighters.get(userId)?.handleText || `#${userId}`;
  }

  /**
   * Returns the handle truncated to HANDLE_MAX characters with ellipsis if needed.
   *
   * @param {string} handle
   * @return {string}
   */
  _fitHandle(handle) {
    if (handle.length > HANDLE_MAX) return handle.slice(0, HANDLE_MAX - 1) + '…';
    return handle;
  }

  /**
   * Emits a leaderboard-updated event with the top 5 ranked fighters for portrait HUD.
   *
   * @return {void}
   */
  _emitPortrait() {
    bus.emit('leaderboard-updated', this._getRankedArray().slice(0, 5).map(([userId, dmg, handle]) => ({
      userId, handle, damage: dmg,
    })));
  }

  // ─── Static methods ───────────────────────────────────────────────────────────

  /**
   * Formats a damage number into a compact string (e.g. 1500 → "2K", 9500000 → "9.5M").
   *
   * @param {number} n
   * @return {string}
   */
  static abbreviateDamage(n) {
    if (n >= 1e6) {
      const v = n / 1e6;
      return (v >= 10 ? Math.round(v) : v.toFixed(1)) + 'M';
    }
    if (n >= 1e3) return Math.round(n / 1e3) + 'K';
    return String(n);
  }

  /**
   * Displays the post-kill MVP card overlay.
   *
   * @param {Phaser.Scene} scene
   * @param {{ bossLabel: string, ranked: Array, killerHandle: string|null }} opts
   * @return {void}
   */
  static showMvpCard(scene, opts) {
    return _showMvpCard(scene, opts);
  }
}

// ─── Backward-compatible exports (used by existing unit tests) ─────────────────

/**
 * Returns a set of pure leaderboard methods operating on the given fighters map.
 *
 * @param {Map} fighters
 * @param {Phaser.Scene} scene
 * @param {Function} render
 * @return {object}
 */
export function makeMethods(fighters, scene, render) {
  function ranked() {
    return [...fighters.entries()].sort((a, b) => b[1].damage - a[1].damage);
  }
  function getRankedArray() {
    return ranked().map(([userId, entry]) => [userId, entry.damage, entry.handle || `#${userId}`]);
  }
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
    damageFor(userId) { return fighters.get(userId)?.damage ?? 0; },
    rankOf(userId) {
      if (!fighters.has(userId)) return null;
      const index = ranked().findIndex(([id]) => id === userId);
      return index === -1 ? null : index + 1;
    },
    getRanked: () => getRankedArray(),
  };
}
