// Which BOSS_TYPES entry a boss wears. A recognizable character (an entry with a
// fixedName, mirrored by App\Enums\BossCharacter) is identified by the name it
// spawned with; every other boss rotates by number through the remaining entries,
// so adding a character never re-skins a generic boss that is already alive.
import { BOSS_TYPES } from '@battlefield/config.js';

export const BOSS_ROTATION = BOSS_TYPES.filter((b) => !b.fixedName);

// Rotation sprite for a generic boss number.
export function bossTypeForNumber(number) {
  return BOSS_ROTATION[number % BOSS_ROTATION.length];
}

// Sprite for a boss state or payload shaped { number, name }.
export function bossTypeOf(boss) {
  return BOSS_TYPES.find((b) => b.fixedName && b.fixedName === boss?.name)
    ?? bossTypeForNumber(boss?.number ?? 0);
}
