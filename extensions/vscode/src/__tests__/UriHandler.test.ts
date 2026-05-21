import { describe, it, expect } from 'vitest';
import { parseAuthUri, AuthUriError } from '../auth/UriHandler';

describe('parseAuthUri', () => {
  it('extracts token and state from a well-formed URI', () => {
    const result = parseAuthUri('vscode://aiorg.aiorg/auth?token=abc&state=xyz');
    expect(result).toEqual({ token: 'abc', state: 'xyz' });
  });

  it('throws on missing token', () => {
    expect(() => parseAuthUri('vscode://aiorg.aiorg/auth?state=xyz'))
      .toThrow(AuthUriError);
  });

  it('throws on missing state', () => {
    expect(() => parseAuthUri('vscode://aiorg.aiorg/auth?token=abc'))
      .toThrow(AuthUriError);
  });

  it('throws on wrong path', () => {
    expect(() => parseAuthUri('vscode://aiorg.aiorg/other?token=abc&state=xyz'))
      .toThrow(AuthUriError);
  });
});
