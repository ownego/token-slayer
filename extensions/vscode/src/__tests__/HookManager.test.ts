import { describe, it, expect } from 'vitest';
import { mergeHooks, removeHooks, type HookConfig } from '../hooks/HookManager';

const config: HookConfig = {
  namespace: 'token_slayer',
  eventsUrl: 'https://token-slayer.app/api/events',
  events: [
    { name: 'Stop', command: 'bash $HOME/.config/token_slayer/send-hook.sh' },
    { name: 'SessionStart', command: 'bash $HOME/.config/token_slayer/send-hook.sh' },
  ],
};

describe('mergeHooks', () => {
  it('adds token-slayer entries to an empty file', () => {
    const result = mergeHooks({}, config);
    expect(result.hooks?.Stop).toEqual([
      { matcher: '*', hooks: [{ type: 'command', command: config.events[0].command, _ns: 'token_slayer' }] },
    ]);
    expect(result.hooks?.SessionStart).toEqual([
      { matcher: '*', hooks: [{ type: 'command', command: config.events[1].command, _ns: 'token_slayer' }] },
    ]);
  });

  it('preserves unrelated entries', () => {
    const existing = {
      theme: 'dark',
      hooks: {
        Stop: [
          { matcher: '*', hooks: [{ type: 'command', command: 'other.sh', _ns: 'other' }] },
        ],
      },
    };

    const result = mergeHooks(existing, config);
    expect((result as any).theme).toBe('dark');
    expect(result.hooks!.Stop).toHaveLength(2);
    expect(result.hooks!.Stop.find((g) => g.hooks[0]._ns === 'other')).toBeDefined();
    expect(result.hooks!.Stop.find((g) => g.hooks[0]._ns === 'token_slayer')).toBeDefined();
  });

  it('replaces stale token-slayer entries when the command changes', () => {
    const existing = {
      hooks: {
        Stop: [
          { matcher: '*', hooks: [{ type: 'command', command: 'old.sh', _ns: 'token_slayer' }] },
        ],
      },
    };

    const result = mergeHooks(existing, config);
    const tokenSlayerEntries = result.hooks!.Stop.filter((g) => g.hooks[0]._ns === 'token_slayer');
    expect(tokenSlayerEntries).toHaveLength(1);
    expect(tokenSlayerEntries[0].hooks[0].command).toBe(config.events[0].command);
  });

  it('isUpToDate returns true when no merge would change anything', () => {
    const first = mergeHooks({}, config);
    expect(mergeHooks(first, config)).toEqual(first);
  });
});

describe('removeHooks', () => {
  it('strips only entries with the matching namespace', () => {
    const existing = {
      hooks: {
        Stop: [
          { matcher: '*', hooks: [{ type: 'command', command: 'other.sh', _ns: 'other' }] },
          { matcher: '*', hooks: [{ type: 'command', command: 'a.sh', _ns: 'token_slayer' }] },
        ],
      },
    };

    const result = removeHooks(existing, 'token_slayer');
    expect(result.hooks!.Stop).toHaveLength(1);
    expect(result.hooks!.Stop[0].hooks[0]._ns).toBe('other');
  });

  it('removes empty event arrays', () => {
    const existing = {
      hooks: {
        Stop: [
          { matcher: '*', hooks: [{ type: 'command', command: 'a.sh', _ns: 'token_slayer' }] },
        ],
      },
    };

    const result = removeHooks(existing, 'token_slayer');
    expect(result.hooks?.Stop).toBeUndefined();
  });
});
