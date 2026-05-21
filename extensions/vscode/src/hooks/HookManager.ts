export interface HookConfig {
  namespace: string;
  eventsUrl: string;
  events: { name: string; command: string }[];
}

export interface HookEntry {
  type: 'command';
  command: string;
  _ns?: string;
}

export interface HookGroup {
  matcher: string;
  hooks: HookEntry[];
}

export interface ClaudeSettings {
  hooks?: Record<string, HookGroup[]>;
  [key: string]: unknown;
}

export function mergeHooks(existing: ClaudeSettings, config: HookConfig): ClaudeSettings {
  const next: ClaudeSettings = { ...existing, hooks: { ...(existing.hooks ?? {}) } };
  const hooks = next.hooks!;

  for (const event of config.events) {
    const list = (hooks[event.name] ?? []).filter((g) => !groupHasNamespace(g, config.namespace));

    list.push({
      matcher: '*',
      hooks: [{ type: 'command', command: event.command, _ns: config.namespace }],
    });

    hooks[event.name] = list;
  }

  return next;
}

export function removeHooks(existing: ClaudeSettings, namespace: string): ClaudeSettings {
  if (!existing.hooks) return existing;

  const next: ClaudeSettings = { ...existing, hooks: { ...existing.hooks } };

  for (const [eventName, groups] of Object.entries(next.hooks!)) {
    const filtered = groups.filter((g) => !groupHasNamespace(g, namespace));
    if (filtered.length === 0) {
      delete next.hooks![eventName];
    } else {
      next.hooks![eventName] = filtered;
    }
  }

  return next;
}

function groupHasNamespace(group: HookGroup, namespace: string): boolean {
  return group.hooks.some((h) => h._ns === namespace);
}
