<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Tokens burned through one org account over rolling windows — every
     * event stamped with this account's id via `events.account_id`,
     * regardless of which user logged it.
     *
     * @return array{hourly:int, daily:int, monthly:int}
     */
    public function forAccount(Account $account): array
    {
        $base = fn (): Builder => Event::where('account_id', $account->id);

        return [
            'hourly' => (int) $base()->where('created_at', '>=', now()->subHour())->sum('tokens'),
            'daily' => (int) $base()->where('created_at', '>=', now()->subDay())->sum('tokens'),
            'monthly' => (int) $base()->where('created_at', '>=', now()->subDays(30))->sum('tokens'),
        ];
    }

    /**
     * One row per account (ordered by email) plus a trailing unassigned row,
     * each with pivot member count and per-window token sums grouped on
     * `events.account_id` — "tokens burned through this account", not
     * "tokens of its members". Single grouped query for the sums; accounts
     * with no recent activity still appear at zero.
     *
     * @return array<int, array{account_id:?int, email:string, plan:?string, memberCount:int, hourly:int, daily:int, monthly:int}>
     */
    public function perAccount(): array
    {
        [$hourAgo, $dayAgo, $monthAgo] = [now()->subHour(), now()->subDay(), now()->subDays(30)];

        $sums = Event::query()
            ->where('created_at', '>=', $monthAgo)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN tokens ELSE 0 END) as hourly', [$hourAgo])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN tokens ELSE 0 END) as daily', [$dayAgo])
            ->selectRaw('SUM(tokens) as monthly')
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

        $unassignedMembers = User::whereDoesntHave('accounts')->count();
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
     * usage descending, annotated with the user's handle and the distinct
     * org-account emails they burned tokens through in the window (via
     * `events.account_id`, not the legacy `users.account_id`).
     *
     * @return array<int, array{user_id:int, handle:string, avatar_url:?string, account_email:?string, hourly:int, daily:int, monthly:int}>
     */
    public function perUser(): array
    {
        [$hourAgo, $dayAgo, $monthAgo] = [now()->subHour(), now()->subDay(), now()->subDays(30)];

        return Event::query()
            ->join('users', 'users.id', '=', 'events.user_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'events.account_id')
            ->where('events.created_at', '>=', $monthAgo)
            ->groupBy('users.id', 'users.slack_handle', 'users.display_name', 'users.name', 'users.avatar_url')
            ->selectRaw('users.id as user_id')
            ->selectRaw('users.slack_handle, users.display_name, users.name, users.avatar_url')
            ->selectRaw($this->accountEmailAggregateExpression())
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as hourly', [$hourAgo])
            ->selectRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) as daily', [$dayAgo])
            ->selectRaw('SUM(events.tokens) as monthly')
            ->orderByRaw('SUM(CASE WHEN events.created_at >= ? THEN events.tokens ELSE 0 END) DESC', [$dayAgo])
            ->get()
            ->map(fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'handle' => $row->slack_handle ?: ($row->display_name ?: ($row->name ?: ('#'.$row->user_id))),
                'avatar_url' => $row->avatar_url,
                'account_email' => $this->normalizeAccountEmailList($row->account_email),
                'hourly' => (int) $row->hourly,
                'daily' => (int) $row->daily,
                'monthly' => (int) $row->monthly,
            ])
            ->all();
    }

    /**
     * Raw SQL for concatenating the distinct joined `accounts.email` values
     * into `perUser`'s `account_email` column. `STRING_AGG` is PostgreSQL's
     * (production) aggregate; SQLite (the test suite's in-memory driver)
     * only understands `GROUP_CONCAT`. Both emit a comma-joined string that
     * `normalizeAccountEmailList()` re-sorts and re-separates uniformly.
     *
     * @return string raw SQL fragment selecting the aliased account_email column
     */
    private function accountEmailAggregateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'GROUP_CONCAT(DISTINCT accounts.email) as account_email'
            : "STRING_AGG(DISTINCT accounts.email, ',') as account_email";
    }

    /**
     * Turns the driver-specific comma-joined raw aggregate value from
     * `accountEmailAggregateExpression()` into a stable, alphabetically
     * sorted, comma-space-separated string (or null when the user burned
     * no tokens through any org account in the window).
     *
     * @param  ?string  $rawList  the raw aggregate column value from the query result
     * @return ?string the normalized, comma-space-joined list of emails
     */
    private function normalizeAccountEmailList(?string $rawList): ?string
    {
        if ($rawList === null || $rawList === '') {
            return null;
        }

        return collect(explode(',', $rawList))
            ->map(fn (string $email): string => trim($email))
            ->unique()
            ->sort()
            ->implode(', ');
    }

    /**
     * Per-account token sums for one user over rolling windows, covering every
     * account the user is a member of plus any they actually burned tokens through.
     *
     * @param  User  $user  the user whose per-account breakdown is being built
     * @return array<int, array{account_id:int, email:string, name:?string, plan:?string, memberCount:int, isMember:bool, util_5h:?int, util_7d:?int, lastProbedAt:?Carbon, hourly:int, daily:int, monthly:int}>
     */
    public function forUserByAccount(User $user): array
    {
        [$hourAgo, $dayAgo, $monthAgo] = [now()->subHour(), now()->subDay(), now()->subDays(30)];

        $sums = Event::query()
            ->where('user_id', $user->id)
            ->whereNotNull('account_id')
            ->where('created_at', '>=', $monthAgo)
            ->groupBy('account_id')
            ->selectRaw('account_id')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN tokens ELSE 0 END) as hourly', [$hourAgo])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN tokens ELSE 0 END) as daily', [$dayAgo])
            ->selectRaw('SUM(tokens) as monthly')
            ->get()
            ->keyBy('account_id');

        $memberIds = $user->accounts()->pluck('accounts.id');
        $accounts = Account::withCount('users')
            ->with('latestUsageSnapshot')
            ->whereIn('id', $memberIds->merge($sums->keys())->unique())
            ->orderBy('email')
            ->get();

        return $accounts->map(fn (Account $account): array => [
            'account_id' => $account->id,
            'email' => $account->email,
            'name' => $account->name,
            'plan' => $account->plan,
            'memberCount' => $account->users_count,
            'isMember' => $memberIds->contains($account->id),
            'util_5h' => $account->latestUsageSnapshot?->util_5h,
            'util_7d' => $account->latestUsageSnapshot?->util_7d,
            'lastProbedAt' => $account->latestUsageSnapshot?->created_at,
            'hourly' => (int) ($sums[$account->id]->hourly ?? 0),
            'daily' => (int) ($sums[$account->id]->daily ?? 0),
            'monthly' => (int) ($sums[$account->id]->monthly ?? 0),
        ])->all();
    }
}
