<?php

namespace App\Services\Profile;

use App\Models\CustomCharacter;
use App\Models\User;

/**
 * Active player-made fighters that a viewer may inspect in the Character tab.
 */
class OtherCustomCharacters
{
    /**
     * Keep another player's fighter separate from the viewer's equipable slot.
     *
     * @param  User  $viewer
     * @return array<int, array<string, mixed>>
     */
    public function for(User $viewer): array
    {
        return CustomCharacter::query()
            ->with('user')
            ->where('enabled', true)
            ->where('user_id', '!=', $viewer->id)
            ->orderBy('user_id')
            ->get()
            ->map(fn (CustomCharacter $character): array => [
                'user_id' => $character->user_id,
                ...$character->sheetState(),
            ])
            ->all();
    }
}
