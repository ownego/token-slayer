import { expect, test, vi } from 'vitest';

vi.mock('phaser', () => ({ default: { Math: { Clamp: (v, a, b) => Math.min(b, Math.max(a, v)) } } }));

import { Bubble } from '@battlefield/bubble.js';

/** A scene fake with just the factories createActivityBubble touches. */
function fakeScene() {
  const obj = extra => ({ setOrigin() { return this; }, setStrokeStyle() { return this; }, setDepth() { return this; }, setVisible() {}, destroy() {}, ...extra });

  return {
    add: { rectangle: (x, y, w, h) => obj({ x, y, width: w, height: h, setSize(w2, h2) { this.width = w2; this.height = h2; } }) },
    addSharpText: (x, y) => obj({ x, y, width: 80, height: 22, setText() {} }),
    tweens: { killTweensOf() {}, add() {} },
  };
}

test('an activity bubble reports its own height, which the scene reads every frame to keep it off the avatar', () => {
  const bubble = new Bubble(fakeScene()).createActivityBubble(100, 50, 'thinking…', 12);

  expect(typeof bubble.height).toBe('function');
  expect(bubble.height()).toBe(26);
});

test('a long action shows on one line, cut with an ellipsis, both when created and when it changes', () => {
  const texts = [];
  const scene = fakeScene();
  const make = scene.addSharpText;
  scene.addSharpText = (x, y, str, style) => {
    const t = make(x, y, str, style);
    texts.push(str);
    t.setText = s => texts.push(s);

    return t;
  };
  const long = 'Bash npx vitest run tests/js/hud/board-hit.test.js --reporter=verbose';
  const bubble = new Bubble(scene).createActivityBubble(100, 50, long, 12, 20);
  bubble.setActivity(long);

  expect(texts).toHaveLength(2);
  texts.forEach(s => {
    expect(s).not.toContain('\n');
    expect(s.length).toBeLessThanOrEqual(20);
    expect(s.endsWith('…')).toBe(true);
  });
});
