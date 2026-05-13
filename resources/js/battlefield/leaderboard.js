import { LOGICAL_WIDTH } from './config.js';

const TOP_ROWS = 5;
const ROW_X = LOGICAL_WIDTH - 8;
const ROW_TOP_Y = 8;
const ROW_HEIGHT = 10;

export function createLeaderboard(scene) {
  const damageByFighter = new Map();
  const rows = Array.from({ length: TOP_ROWS }, (_, i) => {
    return scene.addSharpText(ROW_X, ROW_TOP_Y + i * ROW_HEIGHT, '', {
      fontFamily: 'monospace',
      fontSize: '7px',
      color: '#e2e8f0',
      stroke: '#0f172a',
      strokeThickness: 2,
    }).setOrigin(1, 0);
  });

  function render() {
    const ranked = [...damageByFighter.entries()]
      .sort((a, b) => b[1] - a[1])
      .slice(0, TOP_ROWS);
    for (let i = 0; i < TOP_ROWS; i++) {
      if (ranked[i]) {
        const [userId, total] = ranked[i];
        const fighter = scene.fighters.get(userId);
        const handle = fighter?.handleText || `#${userId}`;
        rows[i].setText(`${i + 1}. ${handle}  ${abbreviateDamage(total)}`);
      } else {
        rows[i].setText('');
      }
    }
  }

  return {
    onHit(userId, damage) {
      if (damage <= 0) {
        return;
      }
      damageByFighter.set(userId, (damageByFighter.get(userId) ?? 0) + damage);
      render();
    },
    reset() {
      damageByFighter.clear();
      render();
    },
    getRanked() {
      return [...damageByFighter.entries()].sort((a, b) => b[1] - a[1]);
    },
  };
}

export function showMvpCard(scene, { bossNumber, ranked, killerHandle }) {
  const cardX = LOGICAL_WIDTH / 2;
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

  const title = scene.addSharpText(cardX, cardY - 26, `BOSS #${bossNumber} DEFEATED`, {
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

function mvpLabel(scene, [userId, total]) {
  const fighter = scene.fighters.get(userId);
  const handle = fighter?.handle?.text || `#${userId}`;
  return `${handle}  —  ${total.toLocaleString()} dmg`;
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
