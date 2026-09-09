<?php

namespace App\Services\Attribution;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Accounts an admin should look at soon: a Claude account whose refresh
 * token expires within 3 days (a real, precise deadline), or a Codex
 * account whose staleness signal has tripped — `earliest_refresh_at` has
 * passed, or (when that field is absent, which is the common case under the
 * current provisioning design — see the Phase 2 spec's 2026-09-02
 * correction) `last_refreshed_at` is over 8 days old, mirroring the `codex`
 * CLI's own internal proactive-refresh heuristic.
 */
final class ExpiringAccountsQuery
{
    /**
     * @var int
     */
    private const int CLAUDE_WARNING_DAYS = 3;

    /**
     * @var int
     */
    private const int CODEX_STALENESS_DAYS = 8;

    /**
     * @return array<int, array{account_id:int, email:?string, name:?string, provider:string, label:string, deadline:?Carbon}>
     */
    public function get(): array
    {
        return $this->claudeRows()->concat($this->codexRows())->values()->all();
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:string, label:string, deadline:?Carbon}>
     */
    private function claudeRows(): Collection
    {
        return Account::query()
            ->where('provider', 'claude')
            ->whereHas('claudeCredential', fn ($query) => $query
                ->whereNotNull('oauth_refresh_expires_at')
                ->where('oauth_refresh_expires_at', '<=', now()->addDays(self::CLAUDE_WARNING_DAYS)))
            ->with('claudeCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => 'claude',
                'label' => 'expires '.$account->claudeCredential->oauth_refresh_expires_at->diffForHumans(),
                'deadline' => $account->claudeCredential->oauth_refresh_expires_at,
            ]);
    }

    /**
     * @return Collection<int, array{account_id:int, email:?string, name:?string, provider:string, label:string, deadline:?Carbon}>
     */
    private function codexRows(): Collection
    {
        return Account::query()
            ->where('provider', 'codex')
            ->whereHas('codexCredential', function ($query): void {
                $query->where(function ($q): void {
                    $q->whereNotNull('earliest_refresh_at')->where('earliest_refresh_at', '<=', now());
                })->orWhere(function ($q): void {
                    $q->whereNull('earliest_refresh_at')->where('last_refreshed_at', '<', now()->subDays(self::CODEX_STALENESS_DAYS));
                });
            })
            ->with('codexCredential')
            ->get()
            ->map(fn (Account $account): array => [
                'account_id' => $account->id,
                'email' => $account->email,
                'name' => $account->name,
                'provider' => 'codex',
                'label' => "hasn't refreshed recently — may need attention",
                'deadline' => null,
            ]);
    }
}
