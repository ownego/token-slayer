import Phaser from 'phaser';
import { fighterDisplayConfig } from '@battlefield/layout.js';

export const ACTIVITY_MAX_CHARS = 18;

/**
 * @param {string} activity
 * @param {number} maxChars
 * @return {string}
 */
function truncateActivity(activity, maxChars = ACTIVITY_MAX_CHARS) {
  if (!activity || activity.length <= maxChars) {
    return activity ?? '';
  }
  return activity.slice(0, maxChars - 1) + '…';
}

/** Manages fighter activity bubbles and hover tooltips. */
export class Bubble {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
  }

  /**
   * Returns true when the current fighter count allows handle/bubble display.
   *
   * @return {boolean}
   */
  fightersAllowBubbles() {
    return fighterDisplayConfig(this.scene.fighters.size, this.scene.mode).showHandle;
  }

  /**
   * Returns the world-space Y for the activity bubble above a fighter's avatar.
   *
   * @param {object} fighter
   * @return {number}
   */
  activityBubbleY(fighter) {
    const avatarRelY = fighter.head?.y ?? 0;
    const avatarRadius = (fighter.head?.displayHeight ?? 28) / 2;
    return (fighter.sprite?.y ?? fighter.pos.y) + avatarRelY - avatarRadius - 28;
  }

  /**
   * Creates or updates the activity bubble on a charge entry.
   *
   * @param {object} entry  charge entry object
   * @param {object} fighter  fighter entry from scene.fighters
   * @param {string|null} activity
   * @return {void}
   */
  updateActivityBubble(entry, fighter, activity) {
    if (!activity) {
      if (entry.bubble) {
        entry.bubble.destroy();
        entry.bubble = null;
      }
      return;
    }
    if (entry.bubble) {
      entry.bubble.setActivity(activity);
    } else {
      const bubbleY = this.activityBubbleY(fighter);
      const fontPx = Math.max(9, Math.round(fighter.displaySize * 0.22));
      const maxChars = Math.max(12, Math.round(fighter.displaySize * 0.35));
      entry.bubble = this.createActivityBubble(fighter.sprite?.x ?? fighter.pos.x, bubbleY, activity, fontPx, maxChars);
    }
    // The hover tooltip takes priority over the activity bubble.
    if (this.scene.hoveredUserId === fighter.id) {
      entry.bubble.setVisible(false);
    }
  }

  /**
   * Creates a floating speech-bubble with a dark background.
   *
   * @param {number} x
   * @param {number} y
   * @param {string} activity
   * @param {number} fontPx
   * @param {number} maxChars
   * @return {{ destroy: Function, setActivity: Function, moveTo: Function, tweenTo: Function, setVisible: Function }}
   */
  createActivityBubble(x, y, activity, fontPx = 14, maxChars = ACTIVITY_MAX_CHARS) {
    const text = this.scene.addSharpText(x, y, truncateActivity(activity, maxChars), {
      fontFamily: 'monospace',
      fontSize: `${fontPx}px`,
      color: '#f1f5f9',
      padding: { left: 8, right: 8, top: 4, bottom: 4 },
    });
    const bg = this.scene.add
      .rectangle(x, y, text.width + 8, text.height + 4, 0x1e293b, 0.92)
      .setOrigin(0.5)
      .setStrokeStyle(1, 0x64748b, 0.9);
    bg.setDepth(100);
    text.setDepth(101);
    return {
      destroy: () => {
        text.destroy();
        bg.destroy();
      },
      setActivity: newActivity => {
        text.setText(truncateActivity(newActivity));
        bg.setSize(text.width + 8, text.height + 4);
      },
      moveTo: (newX, newY) => {
        text.x = newX;
        text.y = newY;
        bg.x = newX;
        bg.y = newY;
      },
      tweenTo: (scene, newX, newY, duration) => {
        scene.tweens.killTweensOf(text);
        scene.tweens.killTweensOf(bg);
        scene.tweens.add({ targets: [text, bg], x: newX, y: newY, duration, ease: 'Linear' });
      },
      setVisible: visible => {
        text.setVisible(visible);
        bg.setVisible(visible);
      },
    };
  }

  /**
   * Shows a hover tooltip for the given fighter, hiding their activity bubble.
   *
   * @param {number} userId
   * @return {void}
   */
  showFighterTooltip(userId) {
    const fighter = this.scene.fighters.get(userId);
    if (!fighter) {
      return;
    }
    const tokens = this.scene.leaderboard?.damageFor(userId) ?? 0;
    const rank = this.scene.leaderboard?.rankOf(userId) ?? null;
    const handle = fighter.handleText || `#${userId}`;
    const rankPrefix = rank ? `#${rank} ` : '';
    const content = `${rankPrefix}${handle} · ${tokens.toLocaleString()} tokens`;
    const fontPx = Math.max(9, Math.round(fighter.displaySize * 0.22));

    if (!this.scene.tooltip) {
      this.scene.tooltip = this.createFighterTooltip(content, fontPx);
    } else {
      this.scene.tooltip.setContent(content, fontPx);
    }

    const margin = 4;
    const halfW = this.scene.tooltip.width() / 2;
    const halfH = this.scene.tooltip.height() / 2;
    const x = Phaser.Math.Clamp(
      fighter.pos.x,
      halfW + margin,
      this.scene.layout.logicalWidth - halfW - margin,
    );
    // Anchor to the same spot as the activity bubble so the tooltip lines up
    // with (and cleanly replaces) the bubble it covers.
    const aboveY = this.activityBubbleY(fighter);
    const avatarCenterY = fighter.pos.y + (fighter.head?.y ?? 0);
    const avatarRadius = (fighter.head?.displayHeight ?? fighter.avatarSize ?? fighter.displaySize) / 2;
    // Flip below the avatar when the tooltip would clip past the top edge.
    const y = aboveY - halfH < margin
      ? avatarCenterY + avatarRadius + halfH + 6
      : aboveY;

    this.scene.tooltip.moveTo(x, y);
    this.scene.tooltip.setVisible(true);
    this.scene.hoveredUserId = userId;

    // Hide the "thinking" activity bubble so it doesn't collide with the tooltip.
    const charge = this.scene.charges.get(userId);
    if (charge?.bubble) {
      charge.bubble.setVisible(false);
    }
  }

  /**
   * Hides the hover tooltip and restores the fighter's activity bubble.
   *
   * @param {number|null} userId
   * @return {void}
   */
  hideFighterTooltip(userId) {
    if (userId != null && this.scene.hoveredUserId !== userId) {
      return;
    }
    const previous = this.scene.hoveredUserId;
    this.scene.hoveredUserId = null;
    if (this.scene.tooltip) {
      this.scene.tooltip.setVisible(false);
    }
    // Restore the activity bubble the tooltip was covering, if still charging.
    if (previous != null) {
      const charge = this.scene.charges.get(previous);
      if (charge?.bubble) {
        charge.bubble.setVisible(true);
      }
    }
  }

  /**
   * Creates a persistent tooltip object (initially hidden).
   *
   * @param {string} content
   * @param {number} fontPx
   * @return {{ setContent: Function, moveTo: Function, setVisible: Function, width: Function, height: Function }}
   */
  createFighterTooltip(content, fontPx = 14) {
    // Mirror the activity bubble's geometry (font scale, padding, box sizing) so
    // the tooltip lines up exactly with the bubble it temporarily replaces.
    const text = this.scene.addSharpText(0, 0, content, {
      fontFamily: 'monospace',
      fontSize: `${fontPx}px`,
      color: '#fde68a',
      padding: { left: 8, right: 8, top: 4, bottom: 4 },
    });
    const bg = this.scene.add
      .rectangle(0, 0, text.width + 8, text.height + 4, 0x1e293b, 0.96)
      .setOrigin(0.5)
      .setStrokeStyle(1, 0xfbbf24, 0.9);
    bg.setDepth(300);
    text.setDepth(301);
    const tooltip = {
      setContent: (newContent, newFontPx = fontPx) => {
        text.setFontSize(newFontPx);
        text.setText(newContent);
        bg.setSize(text.width + 8, text.height + 4);
      },
      moveTo: (x, y) => {
        text.x = x;
        text.y = y;
        bg.x = x;
        bg.y = y;
      },
      setVisible: visible => {
        text.setVisible(visible);
        bg.setVisible(visible);
      },
      width: () => bg.width,
      height: () => bg.height,
    };
    tooltip.setVisible(false);
    return tooltip;
  }
}
