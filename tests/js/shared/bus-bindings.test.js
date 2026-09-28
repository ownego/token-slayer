import { expect, test, vi } from 'vitest';
import { EventEmitter } from 'node:events';
import { bindBus } from '@battlefield/shared/bus-bindings.js';

const emitter = () => { const e = new EventEmitter(); e.off = e.removeListener.bind(e); return e; };

test('unbind removes every handler it bound', () => {
  const bus = emitter();
  const hit = vi.fn();
  const unbind = bindBus(bus, { 'hit-dealt': hit, 'boss-killed': () => {} });
  unbind();
  bus.emit('hit-dealt', {});
  expect(hit).not.toHaveBeenCalled();
  expect(bus.listenerCount('boss-killed')).toBe(0);
});

test('unbind is safe to call twice (shutdown and destroy both fire)', () => {
  const bus = emitter();
  const unbind = bindBus(bus, { a: () => {} });
  unbind();
  expect(() => unbind()).not.toThrow();
});
