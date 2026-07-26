<?php

namespace App\Services\Provisioning;

use App\Enums\GrantStatus;
use App\Models\Device;
use App\Support\CacheKeys;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * One-shot migration helper: converts legacy `account_user` provisioning
 * rows into `devices` + `account_provisioned_grants`. Each provisioned user
 * gets one `'default'` device; every legacy row becomes one grant with a
 * mapped status; a still-alive legacy cache secret is copied to the new
 * per-grant key so an in-flight (<24 h) provision survives the deploy.
 */
final class LegacyGrantBackfiller
{
    /**
     * Backfill devices and grants from legacy pivot rows.
     *
     * @param  array<int, array<string, mixed>>  $legacyRows  account_user rows with provisioned_at set
     * @return void
     */
    public function backfill(array $legacyRows): void
    {
        foreach ($legacyRows as $row) {
            $device = Device::query()->firstOrCreate(
                ['user_id' => $row['user_id'], 'device_id' => Device::DEFAULT_NAME],
                ['name' => 'Default'],
            );

            $status = GrantStatus::Pending;
            if (! empty($row['revoked_at'])) {
                $status = GrantStatus::Revoked;
            } elseif (! empty($row['claimed_at'])) {
                $status = GrantStatus::Claimed;
            }

            $grantId = DB::table('account_provisioned_grants')->insertGetId([
                'account_id' => $row['account_id'],
                'device_id' => $device->id,
                'status' => $status->value,
                'token_uuid' => $row['token_uuid'] ?? null,
                'provisioned_at' => $row['provisioned_at'],
                'claimed_at' => $row['claimed_at'] ?? null,
                'revoked_at' => $row['revoked_at'] ?? null,
                'deprovisioned_at' => $row['deprovisioned_at'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $legacySecret = Cache::get(CacheKeys::legacyProvisionedSetup($row['user_id'], $row['account_id']));
            if ($legacySecret !== null && $status === GrantStatus::Pending) {
                Cache::put(CacheKeys::provisionedGrant($grantId), $legacySecret, CacheKeys::PROVISIONED_GRANT_TTL_SECONDS);
            }
        }
    }
}
