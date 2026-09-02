<?php

namespace App\Services\Attribution;

use Illuminate\Support\Facades\DB;

/**
 * Aggregates "unrecognized" usage into one row per identity beacon
 * (Anthropic organization uuid or Codex chatgpt_account_id) that appears on
 * events but matched no `Account` at ingest time (`account_id IS NULL AND
 * account_org_id IS NOT NULL`). Left joins against both `claude_credentials`
 * and `codex_credentials` surface whether an account now exists for that
 * identity, whichever provider it belongs to, so the panel can offer
 * backfill.
 */
final class UnrecognizedAccountsQuery
{
    /**
     * One row per distinct unrecognized `account_org_id`, ordered by last
     * seen descending. `account_id`/`account_email` are non-null only when an
     * `Account` currently carries that identity. `provider` prefers the
     * matched account's real `accounts.provider` and falls back to a guess
     * derived from the event's own declared `provider` column when no
     * account has claimed this identity yet.
     *
     * @return array<int, array{org_uuid:string, account_id:?int, account_email:?string, provider:string, events:int, tokens:int, users:int, first_seen:string, last_seen:string}>
     */
    public function get(): array
    {
        return DB::table('events')
            ->whereNull('events.account_id')
            ->whereNotNull('events.account_org_id')
            ->leftJoin('claude_credentials', 'claude_credentials.organization_uuid', '=', 'events.account_org_id')
            ->leftJoin('codex_credentials', 'codex_credentials.chatgpt_account_id', '=', 'events.account_org_id')
            ->leftJoin('accounts', 'accounts.id', '=', DB::raw('COALESCE(claude_credentials.account_id, codex_credentials.account_id)'))
            ->groupBy('events.account_org_id', 'accounts.id', 'accounts.email', 'accounts.provider')
            ->selectRaw('events.account_org_id as org_uuid')
            ->selectRaw('accounts.id as account_id')
            ->selectRaw('accounts.email as account_email')
            ->selectRaw('accounts.provider as account_provider')
            ->selectRaw("MAX(CASE WHEN events.provider = 'codex' THEN 'codex' ELSE 'claude' END) as event_provider")
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
                'provider' => $row->account_provider ?? $row->event_provider,
                'events' => (int) $row->events,
                'tokens' => (int) $row->tokens,
                'users' => (int) $row->users,
                'first_seen' => (string) $row->first_seen,
                'last_seen' => (string) $row->last_seen,
            ])
            ->all();
    }
}
