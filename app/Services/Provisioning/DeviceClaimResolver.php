<?php

namespace App\Services\Provisioning;

use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which of a user's devices a provisioning claim belongs to
 * (spec §3). A null fingerprint is the old CLI: it may only ever speak for
 * the legacy `'default'` device. A fingerprint resolves by exact match
 * first, then binds an admin-opened placeholder (oldest first), then binds
 * the still-unbound `'default'`; anything else resolves to nothing — no
 * guessing. Binding runs inside a transaction under `lockForUpdate` so two
 * machines claiming simultaneously can never bind the same row.
 */
final class DeviceClaimResolver
{
    /**
     * Resolve the device a claim request speaks for, binding the
     * fingerprint to a placeholder or the legacy default when applicable.
     *
     * @param  User  $user  the hook-authenticated user
     * @param  string|null  $fingerprint  the client device fingerprint; null = old CLI
     * @return Device|null the resolved (possibly just-bound) device, or null
     */
    public function resolve(User $user, ?string $fingerprint): ?Device
    {
        if ($fingerprint === null) {
            return $user->devices()->where('device_id', Device::DEFAULT_NAME)->first();
        }

        $matched = $user->devices()->where('device_id', $fingerprint)->first();
        if ($matched !== null) {
            return $matched;
        }

        return DB::transaction(function () use ($user, $fingerprint): ?Device {
            $placeholder = $user->devices()
                ->whereNull('device_id')
                ->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($placeholder !== null) {
                $placeholder->update(['device_id' => $fingerprint]);

                return $placeholder;
            }

            $default = $user->devices()
                ->where('device_id', Device::DEFAULT_NAME)
                ->lockForUpdate()
                ->first();
            if ($default !== null) {
                $default->update(['device_id' => $fingerprint]);

                return $default;
            }

            return null;
        });
    }
}
