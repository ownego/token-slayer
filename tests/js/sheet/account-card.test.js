// @vitest-environment jsdom
import { afterEach, expect, test, vi } from 'vitest';
import { accountCard } from '@battlefield/sheet/account-card.js';

afterEach(() => vi.useRealTimers());

const card = outcome => {
  const c = accountCard(7);
  c.$wire = { refreshAccount: vi.fn(() => Promise.resolve(outcome)), $refresh: vi.fn() };

  return c;
};

test('a changed reading shows before → after, re-renders the meters, then counts down a minute', async () => {
  vi.useFakeTimers();
  const c = card({ status: 'changed', from: 42, to: 45 });

  const pending = c.refresh();
  expect(c.busy).toBe(true);
  expect(c.stamp).toBe('checking…');
  await pending;

  expect(c.$wire.refreshAccount).toHaveBeenCalledWith(7);
  expect(c.$wire.$refresh).toHaveBeenCalled();
  expect(c.stamp).toBe('42% → 45%');
  expect(c.stampClass).toBe('changed');
  expect(c.left).toBe(60);
  vi.advanceTimersByTime(60_000);
  expect(c.left).toBe(0);
});

test('a failed probe marks the card, and the button is usable again at once', async () => {
  const c = card({ status: 'failed', error: 'token rejected' });

  await c.refresh();

  expect(c.failed).toBe(true);
  expect(c.stamp).toBe('failed');
  expect(c.left).toBe(0);
  expect(c.busy).toBe(false);
});

test('a cooldown answer counts down what the server says is left', async () => {
  const c = card({ status: 'cooldown', seconds: 41 });

  await c.refresh();

  expect(c.left).toBe(41);
});

test('a member\'s character badge is painted with that fighter\'s own idle sprite', () => {
  const el = document.createElement('span');
  el.dataset.char = 'werebear';

  accountCard(7).paintChar(el);

  expect(el.querySelector('i').style.backgroundImage).toContain('url(');
});

test('an unknown character key leaves the badge blank instead of throwing', () => {
  const el = document.createElement('span');
  el.dataset.char = 'no-such-fighter';

  expect(() => accountCard(7).paintChar(el)).not.toThrow();
  expect(el.querySelector('i')).toBeNull();
});
