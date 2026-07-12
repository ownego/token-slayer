<?php

namespace App\Services\Attribution;

use Illuminate\Support\Facades\DB;

/**
 * Aggregates "unrecognized" usage into one row per Anthropic organization uuid
 * that appears on events but matched no `Account` at ingest time
 * (`account_id IS NULL AND account_org_id IS NOT NULL`). A left join surfaces
 * whether an account for that org exists now, so the panel can offer backfill.
 */
final class UnrecognizedAccountsQuery
{
    /**
     * One row per distinct unrecognized `account_org_id`, ordered by last
     * seen descending. `account_id`/`account_email` are non-null only when an
     * `Account` currently carries that `organization_uuid`.
     *
     * @return array<int, array{org_uuid:string, account_id:?int, account_email:?string, events:int, tokens:int, users:int, first_seen:string, last_seen:string}>
     */
    public function get(): array
    {
        return DB::table('events')
            ->whereNull('events.account_id')
            ->whereNotNull('events.account_org_id')
            ->leftJoin('accounts', 'accounts.organization_uuid', '=', 'events.account_org_id')
            ->groupBy('events.account_org_id', 'accounts.id', 'accounts.email')
            ->selectRaw('events.account_org_id as org_uuid')
            ->selectRaw('accounts.id as account_id')
            ->selectRaw('accounts.email as account_email')
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('SUM(events.tokens) as tokens')
            ->selectRaw('COUNT(DISTINCT events.user_id) as users')
            ->selectRaw('MIN(events.created_at) as first_seen')
            ->selectRaw('MAX(events.created_at) as last_seen')
            ->orderByRaw('MAX(events.created_at) DESC')
            ->get()
            ->map(fn ($row): array => [
                'org_uuid' => $row->org_uuid,
                'account_id' => $row->account_id !== null ? (int) $row->account_id : null,
                'account_email' => $row->account_email,
                'events' => (int) $row->events,
                'tokens' => (int) $row->tokens,
                'users' => (int) $row->users,
                'first_seen' => (string) $row->first_seen,
                'last_seen' => (string) $row->last_seen,
            ])
            ->all();
    }
}
