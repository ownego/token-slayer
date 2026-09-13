<?php

namespace App\Filament\Pages;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\RebalancePlan;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use App\Services\Accounts\AccountMemberStatusQuery;
use App\Services\Accounts\AccountRebalanceRecommender;
use App\Services\Accounts\FleetCapacityForecast;
use App\Services\Accounts\FleetSnapshot;
use App\Services\Accounts\RebalanceRecommendation;
use App\Services\Accounts\RebalanceWindow;
use App\Services\Accounts\StaleMembershipQuery;
use App\Services\Provisioning\DeviceClaimResolver;
use App\Support\CacheKeys;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * On-demand admin page recommending which members should move to a different
 * account so no account exhausts its weekly quota before reset. Nothing here
 * runs automatically — an admin picks a range, triggers the computation,
 * inspects the reasoning behind each row, and only then acts on it.
 */
class AccountRebalance extends Page
{
    /**
     * Only users granted the usage-analytics permission may open this page,
     * matching the other Analytics-group pages.
     *
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_usage_analytics') ?? false;
    }

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Navigation label + page title.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Rebalance';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Account Rebalance';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.account-rebalance';

    /**
     * The adopted plan on screen, or null while this is only a draft.
     *
     * A recalculation is a search result and nothing more; the next search
     * may differ. Adopting one writes it down, and only then is it the thing
     * being worked through.
     *
     * @var int|null
     */
    #[Locked]
    public ?int $planId = null;

    /**
     * When the plan on screen was adopted, so a stale one can say so.
     *
     * @var string|null
     */
    #[Locked]
    public ?string $adoptedAt = null;

    /**
     * How far back the analysis reads: `'week'`, `'month'`, or `'all'`.
     * Admin-chosen rather than fixed, because the right answer differs by
     * question — a week says who is heavy right now, all-time says who is
     * heavy in general — and a plan built on the wrong one is misleading
     * rather than merely imprecise.
     *
     * @var string
     */
    public string $range = 'month';

    /**
     * The most recently computed moves, kept in Livewire state so the table
     * survives re-renders between the recommend action and the row actions
     * on it. Stored as plain arrays (Livewire has no built-in synthesizer
     * for an arbitrary readonly class), each carrying every figure behind
     * the move plus the display labels, so the Blade view never has to go
     * back to the database per row.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $moves = [];

    /**
     * Per-account capacity and fill figures from the last computation, keyed
     * by account id, for the fleet summary above the table.
     *
     * @var array<int, array<string, mixed>>
     */
    #[Locked]
    public array $accounts = [];

    /**
     * Fleet-wide headline figures from the last computation: the fullest
     * account before and after, tokens that fit nowhere, the safety margin
     * in force, and which range produced it.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $summary = [];

    /**
     * Whether a computation has run yet in this page session — the empty
     * table means "press Recalculate" before one has and "the fleet is
     * already balanced" after one has, and those must not read the same.
     *
     * @var bool
     */
    #[Locked]
    public bool $computed = false;

    /**
     * Row indexes of {@see $moves} already carried out, so the plan can be
     * worked through without changing underneath the person working through
     * it.
     *
     * A plan is one target arrangement and its moves are the path to it;
     * applying them in any order arrives there. Recomputing after each one
     * throws that target away and searches again from a half-applied state —
     * the worst state there is, since half a swap has been made and the
     * other half is no longer being asked for. Doing that produced a fresh
     * list of ten every time somebody completed a move, and no way to tell
     * whether they were ever going to finish.
     *
     * @var array<int, int>
     */
    #[Locked]
    public array $applied = [];

    /**
     * Fleet sizing from the last computation: what a typical week and the
     * worst case each demand of the fleet, and how many accounts each would
     * need. Rearranging people cannot help a fleet that is simply too small,
     * and the two figures point at very different purchases.
     *
     * @var array<string, mixed>
     */
    #[Locked]
    public array $capacity = [];

    /**
     * How many hypothetical accounts the what-if is simulating. Zero means
     * no simulation — the sizing figures above stand on their own.
     *
     * @var int
     */
    public int $extraAccounts = 0;

    /**
     * Restore the plan this admin was working through, if they have one.
     *
     * @return void
     */
    public function mount(): void
    {
        $plan = RebalancePlan::query()->latest('id')->first();
        if ($plan === null) {
            return;
        }

        $this->planId = $plan->id;
        $this->moves = $plan->moves;
        $this->accounts = $plan->accounts;
        $this->summary = $plan->summary;
        $this->capacity = $plan->capacity;
        $this->applied = $plan->applied_indexes;
        $this->range = $plan->range;
        $this->extraAccounts = $plan->extra_accounts;
        $this->adoptedAt = $plan->created_at?->toIso8601String();
        $this->computed = true;
    }

    /**
     * Adopt the draft on screen: write it down and start working through it.
     *
     * Kept separate from Recalculate on purpose. A search can be run as often
     * as somebody likes without disturbing the plan a team is part-way
     * through, and reopening the page brings back what was adopted rather
     * than whatever the latest search happened to return.
     *
     * @return Action
     */
    public function adoptPlanAction(): Action
    {
        return Action::make('adoptPlan')
            ->label('Apply this plan')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->visible(fn (): bool => $this->computed && $this->planId === null && $this->moves !== [])
            ->action(function (): void {
                $plan = RebalancePlan::query()->create([
                    'adopted_by' => auth()->id(),
                    'range' => $this->range,
                    'extra_accounts' => $this->extraAccounts,
                    'moves' => $this->moves,
                    'accounts' => $this->accounts,
                    'summary' => $this->summary,
                    'capacity' => $this->capacity,
                    'applied_indexes' => [],
                ]);

                $this->planId = $plan->id;
                $this->applied = [];
                $this->adoptedAt = $plan->created_at?->toIso8601String();

                Notification::make()
                    ->success()
                    ->title('Plan applied')
                    ->body('It will be here when you come back, with whatever you have already done ticked off.')
                    ->send();
            });
    }

    /**
     * Record a completed move against the adopted plan.
     *
     * @param  int  $index  the row that was carried out
     * @return void
     */
    private function markApplied(int $index): void
    {
        $this->applied[] = $index;

        RebalancePlan::query()->whereKey($this->planId)->update([
            'applied_indexes' => $this->applied,
        ]);
    }

    /**
     * Re-run the what-if when the admin changes how many accounts to
     * simulate.
     *
     * @return void
     */
    public function updatedExtraAccounts(): void
    {
        if ($this->computed) {
            $this->compute();
        }
    }

    /**
     * Re-run the analysis when the range changes, so the table on screen
     * always matches the range selected above it.
     *
     * @return void
     */
    public function updatedRange(): void
    {
        if ($this->computed) {
            $this->compute();
        }
    }

    /**
     * The on-demand "Recalculate" header action.
     *
     * @return Action
     */
    public function recommendAction(): Action
    {
        return Action::make('recommend')
            ->label('Recalculate')
            ->icon(Heroicon::OutlinedCalculator)
            ->action(fn () => $this->compute());
    }

    /**
     * Run the recommender over the selected range and store its output on
     * the page.
     *
     * @return void
     */
    public function compute(): void
    {
        // Measured once and handed to both: reading the fleet dominates the
        // cost of this page, and the two answers must come off the same
        // numbers anyway.
        $window = RebalanceWindow::fromFilter($this->range);
        $reading = app(FleetSnapshot::class)->take($window);

        $extra = max(0, $this->extraAccounts);
        $recommender = app(AccountRebalanceRecommender::class);
        $result = $recommender->recommend($window, $reading, $extra);
        $this->capacity = app(FleetCapacityForecast::class)->forecast($window, $reading);

        // What the same fleet reaches WITHOUT the extra account. Planning is
        // cheap once the reading is taken, and without this the simulation
        // cannot say what buying anything actually buys — only where it
        // lands, which is the half of the answer nobody is asking for.
        $withoutExtra = $extra > 0
            ? $recommender->recommend($window, $reading)['peak_fill_after_percent']
            : null;

        $this->accounts = $result['accounts'];
        $this->moves = array_map(
            fn (RebalanceRecommendation $move): array => $this->moveToArray($move, $result['accounts']),
            $result['moves'],
        );
        $this->summary = [
            'peak_fill_before_percent' => $result['peak_fill_before_percent'],
            'peak_fill_after_percent' => $result['peak_fill_after_percent'],
            'unplaced_tokens' => $result['unplaced_tokens'],
            'safety_margin_percent' => $result['safety_margin_percent'],
            'window_label' => $result['window_label'],
            'simulated_accounts' => $result['simulated_accounts'],
            'capacity_tokens' => $result['capacity_tokens'],
            'peak_without_extra_percent' => $withoutExtra,
            'arrivals' => count(array_filter($this->moves, fn (array $move): bool => $move['toAccountIsNew'])),
        ];
        // A recalculation is a draft: it replaces nothing until adopted, so
        // a team part-way through a plan can look without losing their place.
        $this->computed = true;
        $this->planId = null;
        $this->adoptedAt = null;
        $this->applied = [];
    }

    /**
     * Flatten one recommendation into the array shape stored on
     * {@see $moves}, resolving display labels up front.
     *
     * @param  RebalanceRecommendation  $move  the recommendation to convert
     * @param  array<int, array<string, mixed>>  $accounts  per-account summaries, for the email labels
     * @return array<string, mixed>
     */
    private function moveToArray(RebalanceRecommendation $move, array $accounts): array
    {
        return [
            'userId' => $move->userId,
            'userLabel' => User::query()->find($move->userId)?->displayHandle() ?? "#{$move->userId}",
            'fromAccountId' => $move->fromAccountId,
            'fromAccountLabel' => $accounts[$move->fromAccountId]['email'] ?? "#{$move->fromAccountId}",
            'toAccountId' => $move->toAccountId,
            'toAccountLabel' => $accounts[$move->toAccountId]['email'] ?? "#{$move->toAccountId}",
            'toAccountIsNew' => $move->toAccountId < 0,
            'swapWithUserId' => $move->swapWithUserId,
            'swapWithLabel' => $move->swapWithUserId === null
                ? null
                : (User::query()->find($move->swapWithUserId)?->displayHandle() ?? "#{$move->swapWithUserId}"),
            'demandWeeklyTokens' => $move->demandWeeklyTokens,
            'demandPerDayTokens' => $move->demandPerDayTokens,
            'demandBasis' => $move->demandBasis,
            'trailingAvgPerDayTokens' => $move->trailingAvgPerDayTokens,
            'peakWeekTokens' => $move->peakWeekTokens,
            'burstFactor' => $move->burstFactor,
            'quotaWeight' => $move->quotaWeight,
            'quotaWeightWindows' => $move->quotaWeightWindows,
            'daysOfHistory' => $move->daysOfHistory,
            'fromFillBeforePercent' => $move->fromFillBeforePercent,
            'fromFillAfterPercent' => $move->fromFillAfterPercent,
            'toFillBeforePercent' => $move->toFillBeforePercent,
            'toFillAfterPercent' => $move->toFillAfterPercent,
            'confident' => $move->confident,
        ];
    }

    /**
     * Header actions shown on this page.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->adoptPlanAction(), $this->recommendAction()];
    }

    /**
     * Member rows for every connected account, keyed by account id, for the
     * Blade view's member list. Tracked and pending only — an untracked
     * contributor plays no part in a plan, so showing them here would only
     * invite an admin to reason about people the arithmetic ignored.
     *
     * @return array<int, array<int, array{user_id: int, handle: string, status: string}>>
     */
    public function memberRowsByAccount(): array
    {
        $query = app(AccountMemberStatusQuery::class);

        return Account::query()
            ->whereHas('claudeCredential', fn ($q) => $q->whereNotNull('organization_uuid'))
            ->get()
            ->mapWithKeys(fn ($account) => [$account->id => $query->get($account)])
            ->all();
    }

    /**
     * This person's machines, newest bindings last, for the Switch modal.
     * A machine that has connected shows its own name; one still waiting
     * says so, since issuing a grant to it only makes sense for a machine
     * that has not been set up yet.
     *
     * @param  int|string|null  $userId  the person whose machines to list
     * @return array<int, string> device id => label
     */
    private function deviceOptionsFor(int|string|null $userId): array
    {
        if ($userId === null || $userId === '') {
            return [];
        }

        return User::query()->find($userId)?->devices()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($device): array => [
                $device->id => $device->name ?? $device->device_id ?? 'Awaiting a machine (#'.$device->id.')',
            ])
            ->all() ?? [];
    }

    /**
     * Seats held on accounts their holder has already migrated away from,
     * for the Blade view. Recomputed per render rather than stored: it is a
     * cheap query and it must reflect any seat released a moment ago.
     *
     * @return array<int, array{user_id: int, user_label: string, account_id: int, account_label: string, last_used_at: string|null}>
     */
    public function staleMemberships(): array
    {
        $accounts = Account::query()
            ->whereHas('claudeCredential', fn ($q) => $q->whereNotNull('organization_uuid'))
            ->get();

        return app(StaleMembershipQuery::class)->get($accounts, RebalanceWindow::fromFilter($this->range));
    }

    /**
     * Release a seat its holder no longer uses. Unlike a move this costs
     * nobody a re-authentication — they are already working elsewhere — so
     * it is the one action on this page with no cost to anybody.
     *
     * Demotes rather than detaches, which is what the client's declarative
     * reconciliation reads to drop the local credential on its next sync.
     *
     * @return Action
     */
    public function releaseSeatAction(): Action
    {
        return Action::make('releaseSeat')
            ->label('Release seat')
            ->link()
            ->requiresConfirmation()
            ->modalHeading('Stop tracking this member')
            ->modalDescription('They keep working on the account they moved to. The device drops this account\'s local credential on its next sync; the Anthropic token itself is not revoked, as there is no API for that.')
            ->action(function (array $arguments): void {
                $account = Account::query()->findOrFail($arguments['accountId']);
                $account->trackedUsers()->updateExistingPivot($arguments['userId'], [
                    'status' => MembershipStatus::Untracked->value,
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()
                    ->success()
                    ->title('Seat released')
                    ->body("Stopped tracking this member on {$account->email}.")
                    ->send();
            });
    }

    /**
     * The per-row "Why?" action: a read-only modal laying out every figure
     * the move was derived from. The table can only carry a handful of
     * columns, and a recommendation nobody can interrogate is one nobody
     * will act on.
     *
     * @return Action
     */
    public function explainMoveAction(): Action
    {
        return Action::make('explainMove')
            ->label('Why?')
            ->link()
            ->modalHeading('Why this move')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view('filament.pages.partials.rebalance-move-detail', [
                'move' => $this->moves[$arguments['index']],
                'summary' => $this->summary,
            ]));
    }

    /**
     * The per-row "Switch" action: opens the same OAuth code-paste popup
     * `MembersRelationManager` already uses to provision a device on an
     * account, targeting the recommendation's user and destination account.
     * On success, demotes (never detaches) the source account's membership
     * — required for the companion declarative-reconciliation change to
     * ever pick up the removal.
     *
     * The machine is chosen, not invented. An earlier version always opened
     * a fresh placeholder device, and {@see DeviceClaimResolver}
     * matches a known fingerprint exactly before it ever looks at
     * placeholders — so for anyone whose machine had already connected, the
     * grant went to a slot nothing would ever claim while their old account
     * was demoted out from under them. They lost an account and gained
     * nothing.
     *
     * @return Action
     */
    public function switchUserAction(): Action
    {
        return Action::make('switchUser')
            ->label('Switch')
            ->modalHeading('Issue a token on the new account')
            ->modalDescription('Open the authorize URL, log in as the target account, approve, then paste the code back here. The old account will be demoted (not deleted) once this succeeds — the affected device will drop the old local credential on its next sync, but the underlying Anthropic token itself is not revoked (there is no API for that).')
            ->modalSubmitActionLabel('Switch')
            ->fillForm(function (array $arguments): array {
                $started = app(AccountConnectService::class)->start();
                $devices = $this->deviceOptionsFor($arguments['userId'] ?? null);

                return [
                    'authorize_url' => $started['url'],
                    'state' => $started['state'],
                    'user_id' => $arguments['userId'] ?? null,
                    // Their existing machine, not a fresh slot: see the note
                    // on this action for what happens when it is a fresh one.
                    'device_pk' => array_key_first($devices),
                    'code' => '',
                ];
            })
            ->schema([
                TextInput::make('authorize_url')->label('Authorize URL')->readOnly()->copyable(),
                Hidden::make('state'),
                Hidden::make('user_id'),
                Select::make('device_pk')
                    ->label('Machine to move')
                    ->options(fn (Get $get): array => $this->deviceOptionsFor($get('user_id')))
                    ->helperText('The grant is issued to one machine. Leave blank only for a machine that has not connected yet — one already registered answers to its own fingerprint and will never pick up an empty slot.')
                    ->placeholder('+ A machine that has not connected yet…'),
                TextInput::make('code')->label('Paste the code here')->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $user = User::query()->findOrFail($arguments['userId']);
                $fromAccount = Account::query()->findOrFail($arguments['fromAccountId']);
                $toAccount = Account::query()->findOrFail($arguments['toAccountId']);
                $service = app(AccountProvisioningService::class);
                $priorStatus = $toAccount->users()->wherePivot('user_id', $user->id)->first()?->pivot->status;

                DB::transaction(function () use ($service, $user, $toAccount, $data): void {
                    $device = $service->resolveProvisionTarget($user, $data['device_pk'] ?? null);
                    $service->provisionForDevice($user, $toAccount, $device, $data['state'], $data['code']);
                });

                // provisionForDevice() upserts Tracked, which is right for
                // somebody already settled on the account and wrong for
                // everybody else: the grant has been issued and the machine
                // has yet to claim it. Saying Tracked there claims setup
                // finished when it has not — and is why nobody switched here
                // ever showed the pending marker.
                if ($priorStatus !== MembershipStatus::Tracked) {
                    $toAccount->users()->syncWithoutDetaching([
                        $user->id => ['status' => MembershipStatus::Pending->value],
                    ]);
                }

                $fromAccount->trackedUsers()->updateExistingPivot($user->id, [
                    'status' => MembershipStatus::Untracked->value,
                ]);

                // Both rosters changed, and the dashboard reads them through
                // a cache that nothing else here invalidates.
                CacheKeys::forgetAccountMembership($toAccount->id);
                CacheKeys::forgetAccountMembership($fromAccount->id);

                // Ticked off, not recomputed: the rest of this plan still
                // leads to the same arrangement, and re-planning now would
                // abandon it mid-swap.
                if (isset($arguments['index']) && $this->planId !== null) {
                    $this->markApplied((int) $arguments['index']);
                }

                Notification::make()
                    ->success()
                    ->title('Switched')
                    ->body("Issued a token on {$toAccount->email} and stopped tracking {$fromAccount->email}.")
                    ->send();
            });
    }
}
