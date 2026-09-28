<?php

namespace App\Listeners;

use App\Events\FighterCharacterChanged;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;

/**
 * Invalidates {@see CacheKeys::CHARACTER_ROOMMATES} the moment anyone
 * re-equips, rather than waiting out its hour TTL — auto-discovered by
 * Laravel's event listener discovery from this handle()'s type hint.
 */
class ClearCharacterRoommatesCache
{
    /**
     * Forgets the cached roommates grouping so the next read recomputes it.
     *
     * @param  FighterCharacterChanged  $event
     * @return void
     */
    public function handle(FighterCharacterChanged $event): void
    {
        Cache::forget(CacheKeys::CHARACTER_ROOMMATES);
    }
}
