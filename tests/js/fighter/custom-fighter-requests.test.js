import { describe, expect, test } from 'vitest';
import {
  CUSTOM_RETRY_MS,
  begin,
  fail,
  isStale,
  newRequestState,
  settle,
  shouldRequest,
  snapshotSpec,
  want,
  withdraw,
} from '@battlefield/fighter/custom-fighter-requests.js';

describe('a fresh request state', () => {
  test('wants nothing and is loading nothing', () => {
    expect(newRequestState()).toEqual({ wantedKey: null, wantedSpec: null, loadingKey: null, failedKey: null, retryAt: 0 });
  });
});

describe('shouldRequest', () => {
  test('asks for a version the fighter is not showing and nobody is loading', () => {
    expect(shouldRequest(newRequestState(), 'soldier', 'custom-1-b', 0)).toBe(true);
  });

  test('does not ask for the version already on show', () => {
    expect(shouldRequest(newRequestState(), 'custom-1-b', 'custom-1-b', 0)).toBe(false);
  });

  test('does not ask again for a version that is loading right now', () => {
    const state = newRequestState();
    begin(state, 'custom-1-b', { version: 'b' });

    expect(shouldRequest(state, 'soldier', 'custom-1-b', 0)).toBe(false);
  });

  test('a newer version is asked for even while an older one is still loading', () => {
    const state = newRequestState();
    begin(state, 'custom-1-a', { version: 'a' });

    expect(shouldRequest(state, 'soldier', 'custom-1-b', 0)).toBe(true);
  });

  test('a version that failed is not asked for again inside the retry window, and is after it', () => {
    const state = newRequestState();
    begin(state, 'custom-1-b', { version: 'b' });
    settle(state, 'custom-1-b');
    fail(state, 'custom-1-b', 1000);

    expect(shouldRequest(state, 'soldier', 'custom-1-b', 1000 + CUSTOM_RETRY_MS - 1)).toBe(false);
    expect(shouldRequest(state, 'soldier', 'custom-1-b', 1000 + CUSTOM_RETRY_MS)).toBe(true);
  });

  test('the retry window belongs to the version that failed: a different, newer one is not held back by it', () => {
    const state = newRequestState();
    begin(state, 'custom-1-a', { version: 'a' });
    settle(state, 'custom-1-a');
    fail(state, 'custom-1-a', 1000);

    expect(shouldRequest(state, 'soldier', 'custom-1-b', 1001)).toBe(true);
  });

  test('a swap a hit cut short is asked for again once its load has settled', () => {
    const state = newRequestState();
    begin(state, 'custom-1-b', { version: 'b' });
    settle(state, 'custom-1-b');

    expect(shouldRequest(state, 'soldier', 'custom-1-b', 0)).toBe(true);
  });
});

describe('want, begin, settle and isStale', () => {
  test('wanting makes that version the newest wanted, with its spec, and starts no load', () => {
    const state = newRequestState();

    want(state, 'custom-1-b', { version: 'b' });

    expect(state).toMatchObject({ wantedKey: 'custom-1-b', wantedSpec: { version: 'b' }, loadingKey: null });
  });

  test('beginning marks that version as loading', () => {
    const state = newRequestState();

    begin(state, 'custom-1-b');

    expect(state.loadingKey).toBe('custom-1-b');
  });

  test('wanting again states what is wanted now even when no new load is needed: an older load then goes stale', () => {
    const state = newRequestState();
    want(state, 'custom-1-v2', { version: 'v2' });
    begin(state, 'custom-1-v2');

    // the player switched back to v1, which is already on show: nothing to load, but v2 is no longer wanted
    want(state, 'custom-1-v1', { version: 'v1' });

    expect(isStale(state, 'custom-1-v2', 'custom-1-v1')).toBe(true);
    expect(snapshotSpec(state, { spec: { version: 'v1' } })).toEqual({ version: 'v1' });
  });

  test('a version withdrawn and then wanted again, while its load is still in flight, is wanted when it lands', () => {
    const state = newRequestState();
    want(state, 'custom-1-v2', { version: 'v2' });
    begin(state, 'custom-1-v2');
    withdraw(state);

    want(state, 'custom-1-v2', { version: 'v2' });

    expect(isStale(state, 'custom-1-v2', 'soldier')).toBe(false);
  });

  test('settling only clears the load it belongs to, not a newer one', () => {
    const state = newRequestState();
    begin(state, 'custom-1-a');
    begin(state, 'custom-1-b');

    settle(state, 'custom-1-a');

    expect(state.loadingKey).toBe('custom-1-b');
  });

  test('a load for a version that is no longer wanted is stale, so is one already on show', () => {
    const state = newRequestState();
    want(state, 'custom-1-b', { version: 'b' });
    begin(state, 'custom-1-b');

    expect(isStale(state, 'custom-1-b', 'soldier')).toBe(false);
    expect(isStale(state, 'custom-1-a', 'soldier')).toBe(true);
    expect(isStale(state, 'custom-1-b', 'custom-1-b')).toBe(true);
  });

  test('withdrawing (the custom fighter was removed) makes every load stale', () => {
    const state = newRequestState();
    want(state, 'custom-1-b', { version: 'b' });
    begin(state, 'custom-1-b');

    withdraw(state);

    expect(isStale(state, 'custom-1-b', 'wizard')).toBe(true);
    expect(state.wantedSpec).toBeNull();
  });
});

describe('snapshotSpec', () => {
  test('prefers the newest request over the look still on, so a rotate keeps what was asked for', () => {
    const state = newRequestState();
    want(state, 'custom-1-new', { version: 'new' });

    expect(snapshotSpec(state, { spec: { version: 'old' } })).toEqual({ version: 'new' });
  });

  test('falls back to the look on, and to nothing for a roster fighter', () => {
    expect(snapshotSpec(newRequestState(), { spec: { version: 'old' } })).toEqual({ version: 'old' });
    expect(snapshotSpec(newRequestState(), { key: 'soldier' })).toBeNull();
    expect(snapshotSpec(undefined, undefined)).toBeNull();
  });
});
