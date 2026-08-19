<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Rotates a user's hook token: mints a fresh plaintext value, persists only
 * its hash, and hands the plaintext back for one-time display. The previous
 * token stops authenticating immediately — only one hash is stored per user,
 * so every machine still using the old value gets a 401 on its next hook
 * until it picks up the new one.
 */
final class HookTokenRotator
{
    /**
     * Generate a new plaintext hook token, persist its hash, and return the
     * plaintext for one-time display.
     *
     * @param  User  $user  the user whose hook_token column gets overwritten
     * @return string the new plaintext token — never persisted in the clear
     */
    public function rotate(User $user): string
    {
        $plain = Str::random(48);
        $user->forceFill(['hook_token' => hash('sha256', $plain)])->save();

        return $plain;
    }
}
