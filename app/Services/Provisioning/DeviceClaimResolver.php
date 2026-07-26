<?php

namespace App\Services\Provisioning;

use App\Models\Device;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which of a user's devices a provisioning claim belongs to
 * (spec §3). A null fingerprint is the old CLI: it may only ever speak for
 * the legacy `'default'` device. A fingerprint resolves by exact match
 * first, then binds an admin-opened placeholder (oldest first), then binds
 * the still-unbound `'default'`; anything else resolves to nothing — no
 * guessing. Binding runs inside a transaction under `lockForUpdate` so two
 * machines claiming simultaneously can never bind the same row. Same-fingerprint
 * concurrent binds are idempotent: the loser of the race sees the winner's
 * committed bind and returns it.
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
            // Re-check for exact match inside the transaction: if two calls raced,
            // one wins and commits; the loser re-checks and sees the bound device.
            $matched = $user->devices()
                ->where('device_id', $fingerprint)
                ->lockForUpdate()
                ->first();
            if ($matched !== null) {
                return $matched;
            }

            $placeholder = $user->devices()
                ->whereNull('device_id')
                ->orderBy('created_at')->orderBy('id')
                ->lockForUpdate()
                ->first();
            if ($placeholder !== null) {
                try {
                    $placeholder->update(['device_id' => $fingerprint]);

                    return $placeholder;
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    // Race: same fingerprint submitted twice concurrently.
                    // The other thread bound a different placeholder row first.
                    // Return the already-bound device.
                    return $user->devices()->where('device_id', $fingerprint)->first();
                }
            }

            $default = $user->devices()
                ->where('device_id', Device::DEFAULT_NAME)
                ->lockForUpdate()
                ->first();
            if ($default !== null) {
                try {
                    $default->update(['device_id' => $fingerprint]);

                    return $default;
                } catch (QueryException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }

                    // Race: same fingerprint submitted twice concurrently.
                    // The other thread bound the default row first.
                    // Return the already-bound device.
                    return $user->devices()->where('device_id', $fingerprint)->first();
                }
            }

            return null;
        });
    }
}
