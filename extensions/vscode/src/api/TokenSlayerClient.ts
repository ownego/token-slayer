export interface ClientDeps {
  serverUrl: string;
  getToken: () => Promise<string | null>;
  onUnauthorized: () => void;
  fetch: typeof fetch;
}

export class HttpError extends Error {
  constructor(public readonly status: number, public readonly body: unknown) {
    super(`HTTP ${status}`);
  }
}

interface RequestOpts {
  authenticated?: boolean;
}

export class TokenSlayerClient {
  constructor(private readonly deps: ClientDeps) {}

  get<T = unknown>(path: string, opts: RequestOpts = {}): Promise<T> {
    return this.request<T>('GET', path, undefined, opts);
  }

  post<T = unknown>(path: string, body: unknown, opts: RequestOpts = {}): Promise<T> {
    return this.request<T>('POST', path, body, opts);
  }

  private async request<T>(
    method: string,
    path: string,
    body: unknown,
    opts: RequestOpts,
  ): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
    };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    if (opts.authenticated !== false) {
      const token = await this.deps.getToken();
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }
    }

    const response = await this.deps.fetch(`${this.deps.serverUrl}${path}`, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (response.status === 401) {
      this.deps.onUnauthorized();
      throw new HttpError(401, await this.safeBody(response));
    }

    if (response.status >= 400) {
      throw new HttpError(response.status, await this.safeBody(response));
    }

    if (response.status === 204) {
      return undefined as T;
    }
    return (await response.json()) as T;
  }

  private async safeBody(response: Response): Promise<unknown> {
    try {
      return await response.json();
    } catch {
      return null;
    }
  }
}
