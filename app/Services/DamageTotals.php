<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

final class DamageTotals
{
    private const CACHE_KEY = 'damage-totals:global';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * Community-wide damage across rolling windows. Cached briefly; the
     * battlefield client live-increments between page loads.
     *
     * @return array{allTime:int, monthly:int, daily:int, hourly:int}
     */
    public function global(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn (): array => [
            'allTime' => (int) Event::sum('tokens'),
            'monthly' => (int) Event::where('created_at', '>=', now()->subDays(30))->sum('tokens'),
            'daily' => (int) Event::where('created_at', '>=', now()->subDay())->sum('tokens'),
            'hourly' => (int) Event::where('created_at', '>=', now()->subHour())->sum('tokens'),
        ]);
    }

    /**
     * One player's damage across the same rolling windows.
     *
     * @return array{allTime:int, monthly:int, daily:int, hourly:int}
     */
    public function forUser(User $user): array
    {
        return [
            'allTime' => (int) $this->forUserQuery($user)->sum('tokens'),
            'monthly' => (int) $this->forUserQuery($user)->where('created_at', '>=', now()->subDays(30))->sum('tokens'),
            'daily' => (int) $this->forUserQuery($user)->where('created_at', '>=', now()->subDay())->sum('tokens'),
            'hourly' => (int) $this->forUserQuery($user)->where('created_at', '>=', now()->subHour())->sum('tokens'),
        ];
    }

    private function forUserQuery(User $user): Builder
    {
        return Event::where('user_id', $user->id);
    }

    /**
     * Aggregate token usage for one account's members over rolling windows.
     *
     * @return array{hourly:int, daily:int, monthly:int}
     */
    public function forAccount(Account $account): array
    {
        $base = fn (): Builder => Event::whereIn(
            'user_id',
            User::where('account_id', $account->id)->select('id'),
        );

        return [
            'hourly' => (int) $base()->where('created_at', '>=', now()->subHour())->sum('tokens'),
            'daily' => (int) $base()->where('created_at', '>=', now()->subDay())->sum('tokens'),
            'monthly' => (int) $base()->where('created_at', '>=', now()->subDays(30))->sum('tokens'),
        ];
    }

    /**
     * One row per account (ordered by email) plus a trailing unassigned row,
     * each with member count and per-window token sums. Single grouped query
     * for the sums; accounts with no recent activity still appear at zero.
     *
     * @return array<int, array{account_id:?int, email:string, plan:?string, memberCount:int, hourly:int, daily:int, monthly:int}>
     */
    public function perAccount(): array
    {
        [$hourAgo, $dayAgo, $monthAgo] = [now()->subHour(), now()->subDay(), now()->subDays(30)];

        $sums = Event::query()
            ->join('users', 'users.id', '=', 'events.user_id')
            ->where('events.created_at', '>=', $monthAgo)
            ->groupBy('users.account_id')
            ->selectRaw('users.account_id as account_id')
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as hourly', [$hourAgo])
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as daily', [$dayAgo])
            ->selectRaw('SUM(events.tokens) as monthly')
            ->get();

        $byAccount = [];
        $unassignedSums = null;
        foreach ($sums as $row) {
            if ($row->account_id === null) {
                $unassignedSums = $row;
            } else {
                $byAccount[(int) $row->account_id] = $row;
            }
        }

        $rows = Account::withCount('users')->orderBy('email')->get()->map(function (Account $account) use ($byAccount): array {
            $sum = $byAccount[$account->id] ?? null;

            return [
                'account_id' => $account->id,
                'email' => $account->email,
                'plan' => $account->plan,
                'memberCount' => $account->users_count,
                'hourly' => (int) ($sum->hourly ?? 0),
                'daily' => (int) ($sum->daily ?? 0),
                'monthly' => (int) ($sum->monthly ?? 0),
            ];
        })->all();

        $unassignedMembers = User::whereNull('account_id')->count();
        if ($unassignedMembers > 0 || $unassignedSums !== null) {
            $rows[] = [
                'account_id' => null,
                'email' => '— unassigned —',
                'plan' => null,
                'memberCount' => $unassignedMembers,
                'hourly' => (int) ($unassignedSums->hourly ?? 0),
                'daily' => (int) ($unassignedSums->daily ?? 0),
                'monthly' => (int) ($unassignedSums->monthly ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * One row per user with activity in the last 30 days, ordered by daily
     * usage descending, annotated with the user's handle and account email.
     *
     * @return array<int, array{user_id:int, handle:string, avatar_url:?string, account_email:?string, hourly:int, daily:int, monthly:int}>
     */
    public function perUser(): array
    {
        [$hourAgo, $dayAgo, $monthAgo] = [now()->subHour(), now()->subDay(), now()->subDays(30)];

        return Event::query()
            ->join('users', 'users.id', '=', 'events.user_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'users.account_id')
            ->where('events.created_at', '>=', $monthAgo)
            ->groupBy('users.id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url', 'accounts.email')
            ->selectRaw('users.id as user_id')
            ->selectRaw('users.slack_handle, users.display_name, users.name, users.avatar_url')
            ->selectRaw('accounts.email as account_email')
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as hourly', [$hourAgo])
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as daily', [$dayAgo])
            ->selectRaw('SUM(events.tokens) as monthly')
            ->orderByRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) DESC', [$dayAgo])
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'handle' => $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$row->user_id))),
                'avatar_url' => $row->avatar_url,
                'account_email' => $row->account_email,
                'hourly' => (int) $row->hourly,
                'daily' => (int) $row->daily,
                'monthly' => (int) $row->monthly,
            ])
            ->all();
    }
}
