<?php

namespace App\Services\Accounts;

use App\Enums\GrantStatus;
use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seats still held on accounts their holder has already left.
 *
 * When someone migrates — a week on one account, a clean handover, never
 * back — the membership they came from stays tracked. It goes on counting
 * against that account's roster and goes on occupying a provisioning slot,
 * for a person who does their work elsewhere now.
 *
 * This is the cheapest capacity on the page: releasing one costs nobody a
 * re-authentication, because the holder is already working somewhere else.
 * Worth clearing before proposing any move.
 */
final class StaleMembershipQuery
{
    /**
     * How long an account must go unused by a member before the seat counts
     * as abandoned rather than idle.
     *
     * @var int
     */
    private const int UNUSED_DAYS = 7;

    /**
     * @param  HomeAccountResolver  $homes  decides which single account each person actually works on
     */
    public function __construct(private readonly HomeAccountResolver $homes) {}

    /**
     * Seats too young to judge, keyed `"<user>:<account>"`, with the moment
     * they came into being.
     *
     * A seat issued minutes ago has no usage by definition, which is exactly
     * what a Switch leaves behind while the machine has yet to claim it.
     * Without this the page offered to release the very move the admin had
     * just made.
     *
     * @param  array<int, int>  $accountIds  the accounts in scope
     * @return array<string, string> pair => the later of the membership and its newest grant
     */
    private function seatedAt(array $accountIds): array
    {
        $seated = [];

        $memberships = DB::table('account_user')
            ->whereIn('account_id', $accountIds)
            ->get(['user_id', 'account_id', 'created_at']);

        foreach ($memberships as $row) {
            $seated["{$row->user_id}:{$row->account_id}"] = (string) $row->created_at;
        }

        // A re-issued grant lands on a membership row that already existed,
        // so the grant is the younger of the two signals and the one that
        // says setup is under way.
        $grants = DB::table('account_provisioned_grants')
            ->join('devices', 'devices.id', '=', 'account_provisioned_grants.device_id')
            ->whereIn('account_provisioned_grants.account_id', $accountIds)
            ->where('account_provisioned_grants.status', '!=', GrantStatus::Revoked->value)
            ->get(['devices.user_id', 'account_provisioned_grants.account_id', 'account_provisioned_grants.provisioned_at']);

        foreach ($grants as $row) {
            $key = "{$row->user_id}:{$row->account_id}";
            $at = (string) $row->provisioned_at;
            if (! isset($seated[$key]) || $at > $seated[$key]) {
                $seated[$key] = $at;
            }
        }

        return $seated;
    }

    /**
     * Every tracked membership that is not its holder's home and has gone
     * unused, newest departure first.
     *
     * @param  Collection<int, Account>  $accounts  the accounts in scope
     * @param  RebalanceWindow  $window  the range home resolution reads over
     * @return array<int, array{user_id: int, user_label: string, account_id: int, account_label: string, last_used_at: string|null}>
     */
    public function get(Collection $accounts, RebalanceWindow $window): array
    {
        $accountIds = $accounts->pluck('id')->all();
        $homes = $this->homes->resolve($accounts, $window);

        $lastUsed = Event::query()
            ->whereIn('account_id', $accountIds)
            ->selectRaw('user_id')
            ->selectRaw('account_id')
            ->selectRaw('MAX(created_at) as last_used_at')
            ->groupBy('user_id', 'account_id')
            ->get()
            ->mapWithKeys(fn ($row): array => ["{$row->user_id}:{$row->account_id}" => (string) $row->last_used_at])
            ->all();

        // Only tracked seats: a pending grant has not been claimed yet, so
        // nothing about it is abandoned, and reclaiming it would undo a
        // setup still in progress.
        $rows = DB::table('account_user')
            ->whereIn('account_id', $accountIds)
            ->where('status', MembershipStatus::Tracked->value)
            ->get(['user_id', 'account_id']);

        $seated = $this->seatedAt($accountIds);
        $labels = $accounts->pluck('email', 'id')->all();
        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique()->all())->get()->keyBy('id');
        $cutoff = now()->subDays(self::UNUSED_DAYS);

        $stale = [];

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $accountId = (int) $row->account_id;

            if (($homes[$userId] ?? null) === $accountId) {
                continue; // this is where they live
            }

            $at = $lastUsed["{$userId}:{$accountId}"] ?? null;
            if ($at !== null && Carbon::parse($at)->greaterThan($cutoff)) {
                continue; // still in use, however lightly
            }

            $since = $seated["{$userId}:{$accountId}"] ?? null;
            if ($since !== null && Carbon::parse($since)->greaterThan($cutoff)) {
                continue; // too new to have been used yet, let alone abandoned
            }

            $stale[] = [
                'user_id' => $userId,
                'user_label' => $users->get($userId)?->displayHandle() ?? "#{$userId}",
                'account_id' => $accountId,
                'account_label' => (string) ($labels[$accountId] ?? "#{$accountId}"),
                'last_used_at' => $at,
            ];
        }

        usort($stale, fn (array $a, array $b): int => ($b['last_used_at'] ?? '') <=> ($a['last_used_at'] ?? ''));

        return $stale;
    }
}
