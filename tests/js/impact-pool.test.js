import { expect, test, vi } from 'vitest';
// impact.js pulls in bus.js, which constructs a Phaser.Events.EventEmitter
// at module load time — a minimal stub is enough, nothing here fires events.
vi.mock('phaser', () => ({
  default: { Events: { EventEmitter: class { on() {} off() {} emit() {} once() {} } } },
}));
import { createTextPool } from '@battlefield/impact.js';

test('damage numbers are reused instead of created per hit', () => {
  let made = 0;
  const pool = createTextPool(() => ({ id: ++made, visible: false }));
  const a = pool.take(); pool.release(a);
  const b = pool.take();
  expect(b).toBe(a);
  expect(made).toBe(1);
});
