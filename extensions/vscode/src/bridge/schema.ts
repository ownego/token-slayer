export type BridgeMessage =
  | { type: 'connection-state'; state: 'connecting' | 'connected' | 'reconnecting' | 'disconnected' }
  | { type: 'auth-needed' }
  | { type: 'charging-updated'; userId: number; activity: string | null; startedAt: string | null }
  | { type: 'hit-landed'; userId: number; damage: number; bossId: number; bossHpAfter: number; bossMaxHp: number }
  | { type: 'boss-defeated'; bossId: number; killerUserId: number; killerHandle: string | null }
  | { type: 'boss-spawned'; bossId: number; name: string; maxHp: number }
  | { type: 'boss-snapshot'; bossId: number; name: string; maxHp: number; currentHp: number; yourDamage: number }
  | { type: 'install-hooks-requested' };

const num = (v: unknown): v is number => typeof v === 'number' && Number.isFinite(v);
const str = (v: unknown): v is string => typeof v === 'string';
const strOrNull = (v: unknown): v is string | null => v === null || str(v);

export function parseBridgeMessage(input: unknown): BridgeMessage | null {
  if (input === null || typeof input !== 'object') return null;
  const m = input as Record<string, unknown>;
  switch (m.type) {
    case 'auth-needed':
    case 'install-hooks-requested':
      return { type: m.type };
    case 'connection-state':
      if (
        m.state === 'connecting' ||
        m.state === 'connected' ||
        m.state === 'reconnecting' ||
        m.state === 'disconnected'
      ) return { type: 'connection-state', state: m.state };
      return null;
    case 'charging-updated':
      if (num(m.userId) && strOrNull(m.activity) && strOrNull(m.startedAt))
        return { type: 'charging-updated', userId: m.userId, activity: m.activity, startedAt: m.startedAt };
      return null;
    case 'hit-landed':
      if (num(m.userId) && num(m.damage) && num(m.bossId) && num(m.bossHpAfter) && num(m.bossMaxHp))
        return {
          type: 'hit-landed', userId: m.userId, damage: m.damage,
          bossId: m.bossId, bossHpAfter: m.bossHpAfter, bossMaxHp: m.bossMaxHp,
        };
      return null;
    case 'boss-defeated':
      if (num(m.bossId) && num(m.killerUserId) && strOrNull(m.killerHandle))
        return {
          type: 'boss-defeated', bossId: m.bossId,
          killerUserId: m.killerUserId, killerHandle: m.killerHandle,
        };
      return null;
    case 'boss-spawned':
      if (num(m.bossId) && str(m.name) && num(m.maxHp))
        return { type: 'boss-spawned', bossId: m.bossId, name: m.name, maxHp: m.maxHp };
      return null;
    case 'boss-snapshot':
      if (num(m.bossId) && str(m.name) && num(m.maxHp) && num(m.currentHp) && num(m.yourDamage))
        return {
          type: 'boss-snapshot',
          bossId: m.bossId,
          name: m.name,
          maxHp: m.maxHp,
          currentHp: m.currentHp,
          yourDamage: m.yourDamage,
        };
      return null;
    default:
      return null;
  }
}
