<?php

namespace App\Services\Attribution;

use App\Models\Account;
use App\Models\Event;
use App\Support\CacheKeys;

/**
 * Re-attributes unrecognized events (org beacon present, `account_id` null) to
 * the `Account` whose `organization_uuid` exactly matches the event's
 * `account_org_id`. The update is in place — the append-only invariant guards
 * usage facts (tokens/provider/timestamp), not the attribution FK. Idempotent:
 * only rows with a null `account_id` are ever touched.
 */
final class EventAttributionBackfiller
{
    /**
     * Attribute unrecognized events to their matching account. With no
     * argument, every org uuid that has a matching account is backfilled; with
     * an org uuid, only that org (still guarded by a matching account). Forgets
     * the {@see CacheKeys::forgetDamageTotals()} aggregate once if any rows changed.
     *
     * @param  ?string  $orgUuid  limit to one organization uuid, or null for all matchable orgs
     * @return array<string, int> matched org uuid => number of events attributed (only orgs where rows changed)
     */
    public function backfill(?string $orgUuid = null): array
    {
        $map = Account::query()
            ->whereNotNull('organization_uuid')
            ->when($orgUuid !== null, fn ($query) => $query->where('organization_uuid', $orgUuid))
            ->pluck('id', 'organization_uuid');

        $attributed = [];

        foreach ($map as $org => $accountId) {
            $count = Event::query()
                ->whereNull('account_id')
                ->where('account_org_id', $org)
                ->update(['account_id' => $accountId]);

            if ($count > 0) {
                $attributed[$org] = $count;
            }
        }

        if ($attributed !== []) {
            CacheKeys::forgetDamageTotals();
        }

        return $attributed;
    }
}
