import { bus } from './bus.js';

const TOP_ROWS = 5;
const ROW_TOP_Y = 10;
const ROW_HEIGHT = 14;
const HANDLE_WIDTH = 10;
const DAMAGE_WIDTH = 4;

export function createLeaderboard(scene) {
  const fighters = new Map();
  const isPortrait = scene.mode === 'portrait';

  const rows = isPortrait
    ? []
    : Array.from({ length: TOP_ROWS }, (_, i) => {
      return scene.addSharpText(scene.layout.logicalWidth - 8, ROW_TOP_Y + i * ROW_HEIGHT, '', {
        fontFamily: 'monospace',
        fontSize: '10px',
        color: '#e2e8f0',
        stroke: '#0f172a',
        strokeThickness: 3,
      }, 3).setOrigin(1, 0);
    });

  function resolveHandle(userId, stored) {
    return stored || scene.fighters.get(userId)?.handleText || `#${userId}`;
  }

  function ranked() {
    return [...fighters.entries()]
      .sort((a, b) => b[1].damage - a[1].damage);
  }

  function render() {
    const top = ranked().slice(0, TOP_ROWS);

    if (isPortrait) {
      bus.emit('leaderboard-updated', top.map(([userId, entry]) => ({
        userId,
        handle: resolveHandle(userId, entry.handle),
        damage: entry.damage,
      })));
      return;
    }

    for (let i = 0; i < TOP_ROWS; i++) {
      if (top[i]) {
        const [userId, entry] = top[i];
        const handle = fitHandle(resolveHandle(userId, entry.handle));
        const damage = abbreviateDamage(entry.damage).padStart(DAMAGE_WIDTH);
        rows[i].setText(`${i + 1}. ${handle} ${damage}`);
      } else {
        rows[i].setText('');
      }
    }
  }

  function fitHandle(handle) {
    if (handle.length > HANDLE_WIDTH) {
      return handle.slice(0, HANDLE_WIDTH - 1) + '…';
    }
    return handle.padEnd(HANDLE_WIDTH);
  }

  return {
    seed(entries) {
      fighters.clear();
      for (const entry of entries ?? []) {
        if (entry.damage <= 0) {
          continue;
        }
        fighters.set(entry.userId, {
          damage: entry.damage,
          handle: entry.handle ?? '',
        });
      }
      render();
    },
    onHit(userId, damage, handle) {
      if (damage <= 0) {
        return;
      }
      const existing = fighters.get(userId);
      fighters.set(userId, {
        damage: (existing?.damage ?? 0) + damage,
        handle: handle || existing?.handle || '',
      });
      render();
    },
    reset() {
      fighters.clear();
      render();
    },
    damageFor(userId) {
      return fighters.get(userId)?.damage ?? 0;
    },
    rankOf(userId) {
      if (!fighters.has(userId)) {
        return null;
      }
      const index = ranked().findIndex(([id]) => id === userId);
      return index === -1 ? null : index + 1;
    },
    getRanked() {
      return ranked().map(([userId, entry]) => [
        userId,
        entry.damage,
        resolveHandle(userId, entry.handle),
      ]);
    },
  };
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
  const cardY = 120;
  const cardW = 280;
  const cardH = 80;
  const mvp = ranked[0];
  const runnerUp = ranked[1];

  const bg = scene.add
    .rectangle(cardX, cardY, cardW, cardH, 0x0f172a, 0.96)
    .setOrigin(0.5)
    .setStrokeStyle(2, 0xfbbf24, 1)
    .setDepth(200)
    .setAlpha(0);

  const title = scene.addSharpText(cardX, cardY - 26, `${bossLabel} DEFEATED`, {
    fontFamily: 'monospace',
    fontSize: '12px',
    color: '#fbbf24',
  });
  title.setDepth(202).setAlpha(0);

  const mvpLine = scene.addSharpText(
    cardX,
    cardY - 6,
    mvp ? `1st  ${mvpLabel(scene, mvp)}` : 'no damage logged',
    {
      fontFamily: 'monospace',
      fontSize: '10px',
      color: '#fde68a',
    },
  );
  mvpLine.setDepth(202).setAlpha(0);

  const runnerLine = scene.addSharpText(
    cardX,
    cardY + 10,
    runnerUp ? `2nd  ${mvpLabel(scene, runnerUp)}` : '',
    {
      fontFamily: 'monospace',
      fontSize: '9px',
      color: '#cbd5e1',
    },
  );
  runnerLine.setDepth(202).setAlpha(0);

  const killerLine = scene.addSharpText(
    cardX,
    cardY + 26,
    killerHandle ? `killing blow: ${killerHandle}` : '',
    {
      fontFamily: 'monospace',
      fontSize: '8px',
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

function abbreviateDamage(n) {
  if (n >= 1e6) {
    const v = n / 1e6;
    return (v >= 10 ? Math.round(v) : v.toFixed(1)) + 'M';
  }
  if (n >= 1e3) {
    return Math.round(n / 1e3) + 'K';
  }
  return String(n);
}
