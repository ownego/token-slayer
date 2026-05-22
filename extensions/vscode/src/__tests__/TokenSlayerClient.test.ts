import { describe, it, expect, vi, beforeEach } from 'vitest';
import { TokenSlayerClient, type ClientDeps } from '../api/TokenSlayerClient';

function makeDeps(overrides: Partial<ClientDeps> = {}): ClientDeps {
  return {
    serverUrl: 'https://example.test',
    getToken: vi.fn().mockResolvedValue('bearer-abc'),
    onUnauthorized: vi.fn(),
    fetch: vi.fn(),
    ...overrides,
  };
}

describe('TokenSlayerClient', () => {
  beforeEach(() => vi.clearAllMocks());

  it('attaches bearer and parses JSON on 2xx', async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ user: { id: 1 } }), { status: 200 }),
    );
    const deps = makeDeps({ fetch });
    const client = new TokenSlayerClient(deps);

    const result = await client.get<{ user: { id: number } }>('/api/ide/me');

    expect(result.user.id).toBe(1);
    expect(fetch).toHaveBeenCalledWith(
      'https://example.test/api/ide/me',
      expect.objectContaining({
        method: 'GET',
        headers: expect.objectContaining({ Authorization: 'Bearer bearer-abc' }),
      }),
    );
  });

  it('throws on 410 with a typed error', async () => {
    const fetch = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ error: 'token_invalid_or_expired' }), { status: 410 }),
    );
    const client = new TokenSlayerClient(makeDeps({ fetch }));

    await expect(client.post('/api/ide/auth/exchange', { token: 'x', state: 'y' }))
      .rejects.toMatchObject({ status: 410 });
  });

  it('fires onUnauthorized once on 401 and rethrows', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('', { status: 401 }));
    const onUnauthorized = vi.fn();
    const client = new TokenSlayerClient(makeDeps({ fetch, onUnauthorized }));

    await expect(client.get('/api/ide/me')).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('skips bearer when token is absent and endpoint is unauthenticated', async () => {
    const fetch = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    const deps = makeDeps({ fetch, getToken: vi.fn().mockResolvedValue(null) });
    const client = new TokenSlayerClient(deps);

    await client.post('/api/ide/auth/exchange', { token: 't', state: 's' }, { authenticated: false });

    const call = fetch.mock.calls[0]![1] as RequestInit;
    expect((call.headers as Record<string, string>).Authorization).toBeUndefined();
  });
});
