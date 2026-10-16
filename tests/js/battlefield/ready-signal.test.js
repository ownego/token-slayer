import { afterEach, beforeEach, expect, test, vi } from 'vitest';
import { announceReady, whenReady } from '@battlefield/ready-signal.js';

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('window', new EventTarget());
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

test('readiness is announced once and a late subscriber runs immediately', () => {
  const event = vi.fn();
  window.addEventListener('battlefield-ready', event);

  announceReady();
  announceReady();
  const ready = vi.fn();
  whenReady(ready, 15000);

  expect(window.__battlefieldReady).toBe(true);
  expect(event).toHaveBeenCalledTimes(1);
  expect(ready).toHaveBeenCalledTimes(1);
});

test('a waiting callback runs once at readiness, or at its ceiling if the arena stalls', () => {
  const ready = vi.fn();
  whenReady(ready, 15000);
  announceReady();
  vi.advanceTimersByTime(15000);
  expect(ready).toHaveBeenCalledTimes(1);

  vi.stubGlobal('window', new EventTarget());
  const stalled = vi.fn();
  whenReady(stalled, 15000);
  vi.advanceTimersByTime(15000);
  expect(stalled).toHaveBeenCalledTimes(1);
});

test('a sheet deep link is read once and stripped from the address bar, keeping other params and the hash', async () => {
  const { consumeSheetLink } = await import('@battlefield/ready-signal.js');
  const replaceState = vi.fn();

  const link = consumeSheetLink({ href: 'https://ts.test/battlefield?sheet=character&editor=1&x=2#top' }, { state: null, replaceState });

  expect(link).toEqual({ sheet: 'character', editor: true });
  expect(replaceState).toHaveBeenCalledWith(null, '', '/battlefield?x=2#top');
});

test('a page with no deep link is left alone', async () => {
  const { consumeSheetLink } = await import('@battlefield/ready-signal.js');
  const replaceState = vi.fn();

  expect(consumeSheetLink({ href: 'https://ts.test/battlefield' }, { state: null, replaceState })).toEqual({ sheet: null, editor: false });
  expect(replaceState).not.toHaveBeenCalled();
});
