<?php

namespace App\Services\Profile;

use App\Listeners\ClearCharacterRoommatesCache;
use App\Models\User;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Who else has each character equipped right now — for the Character tab's
 * "teammates on this slot" avatars. Cached for an hour under
 * {@see CacheKeys::CHARACTER_ROOMMATES} (equip changes are infrequent, and
 * this feeds decorative UI, not gameplay); invalidated eagerly by
 * {@see ClearCharacterRoommatesCache} on every
 * FighterCharacterChanged broadcast rather than waiting out the TTL, so a
 * freshly-equipped character shows up for other viewers within the same
 * hour it happened.
 */
class CharacterRoommates
{
    /**
     * Every character currently equipped by anyone, grouped by its key.
     *
     * @return array<string, array<int, array{user_id: int, handle: string, avatar: ?string}>>
     */
    public function for(): array
    {
        return Cache::remember(CacheKeys::CHARACTER_ROOMMATES, now()->addHour(), function (): array {
            return User::query()
                ->whereNotNull('equipped_character')
                ->get(['id', 'equipped_character', 'slack_handle', 'display_name', 'name', 'avatar_url'])
                ->groupBy(fn (User $user): string => $user->equipped_character->value)
                ->map(fn ($users): array => $users->map(fn (User $u): array => [
                    'user_id' => $u->id,
                    'handle' => $u->displayHandle(),
                    'avatar' => $u->avatarProxyUrl(),
                ])->all())
                ->all();
        });
    }
}
