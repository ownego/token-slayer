import type { TokenSlayerClient } from '../api/TokenSlayerClient';

const BEARER_KEY = 'token-slayer.bearer';

export interface AuthState {
  signedIn: boolean;
}

interface SecretStorageLike {
  get(key: string): Thenable<string | undefined>;
  store(key: string, value: string): Thenable<void>;
  delete(key: string): Thenable<void>;
}

export interface AuthServiceDeps {
  secrets: SecretStorageLike;
  client: TokenSlayerClient;
  openBrowser: (url: string) => Promise<void>;
  serverUrl: string;
}

export class AuthService {
  private pendingState: string | null = null;
  private listeners = new Set<(state: AuthState) => void>();

  constructor(private readonly deps: AuthServiceDeps) {}

  onAuthChanged(listener: (state: AuthState) => void): () => void {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }

  async getToken(): Promise<string | null> {
    return (await this.deps.secrets.get(BEARER_KEY)) ?? null;
  }

  async isSignedIn(): Promise<boolean> {
    return (await this.getToken()) !== null;
  }

  async startSignIn(): Promise<void> {
    const state = this.randomState();
    this.pendingState = state;

    const url = `${this.deps.serverUrl}/auth/slack?return=ide&state=${encodeURIComponent(state)}`;
    await this.deps.openBrowser(url);
  }

  async completeSignIn(payload: { token: string; state: string }): Promise<void> {
    if (this.pendingState === null || payload.state !== this.pendingState) {
      throw new Error('sign-in state mismatch');
    }

    this.pendingState = null;

    const result = await this.deps.client.post<{ bearer: string }>(
      '/api/ide/auth/exchange',
      payload,
      { authenticated: false },
    );

    await this.deps.secrets.store(BEARER_KEY, result.bearer);
    this.fire({ signedIn: true });
  }

  async signOut(): Promise<void> {
    try {
      await this.deps.client.post('/api/ide/auth/revoke', {});
    } catch {
      // Server-side revoke is best-effort; the client-side clear is what matters.
    }

    await this.deps.secrets.delete(BEARER_KEY);
    this.fire({ signedIn: false });
  }

  async handleUnauthorized(): Promise<void> {
    await this.deps.secrets.delete(BEARER_KEY);
    this.fire({ signedIn: false });
  }

  pendingStateForTest(): string | null {
    return this.pendingState;
  }

  private randomState(): string {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  }

  private fire(state: AuthState): void {
    for (const listener of this.listeners) {
      try {
        listener(state);
      } catch {
        // listeners shouldn't crash other listeners
      }
    }
  }
}
