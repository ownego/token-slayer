import { describe, it, expect, vi, beforeEach } from 'vitest';
import { AuthService } from '../auth/AuthService';

class FakeSecretStorage {
  private map = new Map<string, string>();
  get = vi.fn(async (key: string) => this.map.get(key));
  store = vi.fn(async (key: string, value: string) => { this.map.set(key, value); });
  delete = vi.fn(async (key: string) => { this.map.delete(key); });
}

function makeClient(overrides: Partial<{ post: ReturnType<typeof vi.fn> }> = {}) {
  return {
    post: vi.fn().mockResolvedValue({ bearer: 'B' }),
    ...overrides,
  } as any;
}

describe('AuthService', () => {
  beforeEach(() => vi.clearAllMocks());

  it('generates a fresh random state per startSignIn', async () => {
    const secrets = new FakeSecretStorage();
    const openBrowser = vi.fn().mockResolvedValue(undefined);
    const auth = new AuthService({
      secrets: secrets as any,
      client: makeClient(),
      openBrowser,
      serverUrl: 'https://example.test',
    });

    await auth.startSignIn();
    await auth.startSignIn();

    const url1 = new URL(openBrowser.mock.calls[0]![0] as string);
    const url2 = new URL(openBrowser.mock.calls[1]![0] as string);
    expect(url1.searchParams.get('state')).not.toBe(url2.searchParams.get('state'));
    expect(url1.searchParams.get('return')).toBe('ide');
    expect(url1.pathname).toBe('/auth/slack');
  });

  it('completeSignIn rejects mismatched state', async () => {
    const secrets = new FakeSecretStorage();
    const auth = new AuthService({
      secrets: secrets as any,
      client: makeClient(),
      openBrowser: vi.fn(),
      serverUrl: 'https://example.test',
    });

    await auth.startSignIn();
    await expect(auth.completeSignIn({ token: 't', state: 'WRONG' })).rejects.toThrow(/state/);
  });

  it('completeSignIn stores bearer and fires onAuthChanged', async () => {
    const secrets = new FakeSecretStorage();
    const client = makeClient();
    const auth = new AuthService({
      secrets: secrets as any,
      client,
      openBrowser: vi.fn(),
      serverUrl: 'https://example.test',
    });

    let last: { signedIn: boolean } | null = null;
    auth.onAuthChanged((s) => { last = s; });

    await auth.startSignIn();
    const state = auth.pendingStateForTest()!;

    await auth.completeSignIn({ token: 't', state });

    expect(secrets.store).toHaveBeenCalledWith('token-slayer.bearer', 'B');
    expect(last).toEqual({ signedIn: true });
  });

  it('getToken returns the persisted bearer', async () => {
    const secrets = new FakeSecretStorage();
    await secrets.store('token-slayer.bearer', 'existing');
    const auth = new AuthService({
      secrets: secrets as any,
      client: makeClient(),
      openBrowser: vi.fn(),
      serverUrl: 'https://example.test',
    });

    expect(await auth.getToken()).toBe('existing');
  });

  it('signOut clears storage and fires onAuthChanged(signedOut)', async () => {
    const secrets = new FakeSecretStorage();
    await secrets.store('token-slayer.bearer', 'existing');
    const client = makeClient();
    const auth = new AuthService({
      secrets: secrets as any,
      client,
      openBrowser: vi.fn(),
      serverUrl: 'https://example.test',
    });

    let last: { signedIn: boolean } | null = null;
    auth.onAuthChanged((s) => { last = s; });

    await auth.signOut();

    expect(client.post).toHaveBeenCalledWith('/api/ide/auth/revoke', {});
    expect(await secrets.get('token-slayer.bearer')).toBeUndefined();
    expect(last).toEqual({ signedIn: false });
  });

  it('handleUnauthorized clears bearer and fires signedOut', async () => {
    const secrets = new FakeSecretStorage();
    await secrets.store('token-slayer.bearer', 'existing');
    const auth = new AuthService({
      secrets: secrets as any,
      client: makeClient(),
      openBrowser: vi.fn(),
      serverUrl: 'https://example.test',
    });

    let last: { signedIn: boolean } | null = null;
    auth.onAuthChanged((s) => { last = s; });

    await auth.handleUnauthorized();

    expect(await secrets.get('token-slayer.bearer')).toBeUndefined();
    expect(last).toEqual({ signedIn: false });
  });
});
