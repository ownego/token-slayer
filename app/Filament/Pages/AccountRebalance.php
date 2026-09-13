<?php

namespace App\Filament\Pages;

use App\Enums\MembershipStatus;
use App\Models\Account;
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
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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

        $result = app(AccountRebalanceRecommender::class)->recommend($window, $reading);
        $this->capacity = app(FleetCapacityForecast::class)->forecast($window, max(0, $this->extraAccounts), $reading);

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
        ];
        $this->computed = true;
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
        return [$this->recommendAction()];
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
     * @return Action
     */
    public function switchUserAction(): Action
    {
        return Action::make('switchUser')
            ->label('Switch')
            ->modalHeading('Issue a token on the new account')
            ->modalDescription('Open the authorize URL, log in as the target account, approve, then paste the code back here. The old account will be demoted (not deleted) once this succeeds — the affected device will drop the old local credential on its next sync, but the underlying Anthropic token itself is not revoked (there is no API for that).')
            ->modalSubmitActionLabel('Switch')
            ->fillForm(function (): array {
                $started = app(AccountConnectService::class)->start();

                return [
                    'authorize_url' => $started['url'],
                    'state' => $started['state'],
                    'code' => '',
                ];
            })
            ->schema([
                TextInput::make('authorize_url')->label('Authorize URL')->readOnly()->copyable(),
                Hidden::make('state'),
                TextInput::make('code')->label('Paste the code here')->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $user = User::query()->findOrFail($arguments['userId']);
                $fromAccount = Account::query()->findOrFail($arguments['fromAccountId']);
                $toAccount = Account::query()->findOrFail($arguments['toAccountId']);
                $service = app(AccountProvisioningService::class);

                DB::transaction(function () use ($service, $user, $toAccount, $data): void {
                    $device = $service->resolveProvisionTarget($user, null);
                    $service->provisionForDevice($user, $toAccount, $device, $data['state'], $data['code']);
                });

                $fromAccount->trackedUsers()->updateExistingPivot($user->id, [
                    'status' => MembershipStatus::Untracked->value,
                ]);

                Notification::make()
                    ->success()
                    ->title('Switched')
                    ->body("Issued a token on {$toAccount->email} and stopped tracking {$fromAccount->email}.")
                    ->send();
            });
    }
}
