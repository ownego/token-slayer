<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Services\Accounts\AccountMemberStatusQuery;
use App\Services\Accounts\AccountRebalanceRecommender;
use App\Services\Accounts\RebalanceRecommendation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Locked;
use UnitEnum;

/**
 * On-demand admin page recommending which tracked members should move to a
 * different account so no account exhausts its weekly quota before reset.
 * Nothing here runs automatically — an admin triggers the computation and
 * reviews it before acting (see the "Switch" row action, added in a later
 * task).
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
     * The most recently computed moves, kept in Livewire state so the table
     * survives re-renders between the recommend action and any row action
     * on it (added in a later task). Empty until "Tính lại phân bổ" runs.
     * Stored as plain arrays (Livewire has no built-in synthesizer for an
     * arbitrary readonly class), each shaped like
     * `RebalanceRecommendation`'s own public properties.
     *
     * @var array<int, array{userId: int, fromAccountId: int, toAccountId: int, fromProjectedBefore: int, fromProjectedAfter: int, toProjectedBefore: int, toProjectedAfter: int, demandTokensPerDay: float, demandBasis: string, confident: bool}>
     */
    #[Locked]
    public array $moves = [];

    /**
     * Fleet-wide overflow tokens the last computation could not resolve
     * with any move, or 0 before the first computation.
     *
     * @var float
     */
    #[Locked]
    public float $unresolvedOverflowTokens = 0.0;

    /**
     * The on-demand "Tính lại phân bổ" header action: runs the recommender
     * and stores its output on the page for the Blade view to render.
     *
     * @return Action
     */
    public function recommendAction(): Action
    {
        return Action::make('recommend')
            ->label('Tính lại phân bổ')
            ->icon(Heroicon::OutlinedCalculator)
            ->action(function (): void {
                $result = app(AccountRebalanceRecommender::class)->recommend();
                $this->moves = array_map($this->recommendationToArray(...), $result['moves']);
                $this->unresolvedOverflowTokens = $result['unresolved_overflow_tokens'];
            });
    }

    /**
     * Converts one recommendation DTO into the plain array shape stored on
     * {@see $moves}.
     *
     * @param  RebalanceRecommendation  $recommendation  the recommendation to convert
     * @return array{userId: int, fromAccountId: int, toAccountId: int, fromProjectedBefore: int, fromProjectedAfter: int, toProjectedBefore: int, toProjectedAfter: int, demandTokensPerDay: float, demandBasis: string, confident: bool}
     */
    private function recommendationToArray(RebalanceRecommendation $recommendation): array
    {
        return [
            'userId' => $recommendation->userId,
            'fromAccountId' => $recommendation->fromAccountId,
            'toAccountId' => $recommendation->toAccountId,
            'fromProjectedBefore' => $recommendation->fromProjectedBefore,
            'fromProjectedAfter' => $recommendation->fromProjectedAfter,
            'toProjectedBefore' => $recommendation->toProjectedBefore,
            'toProjectedAfter' => $recommendation->toProjectedAfter,
            'demandTokensPerDay' => $recommendation->demandTokensPerDay,
            'demandBasis' => $recommendation->demandBasis,
            'confident' => $recommendation->confident,
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
     * Whether the member list also shows Untracked members (red dot).
     * Defaults to false, mirroring MembersRelationManager's "Unverified
     * members" toggle — an untracked contributor is noise for this page's
     * main purpose until an admin asks to see it.
     *
     * @var bool
     */
    public bool $showUntracked = false;

    /**
     * Member rows for every connected account, keyed by account id, for the
     * Blade view's member list. Honors {@see $showUntracked}.
     *
     * @return array<int, array<int, array{user_id: int, handle: string, status: string}>>
     */
    public function memberRowsByAccount(): array
    {
        $query = app(AccountMemberStatusQuery::class);

        return Account::query()
            ->whereHas('claudeCredential', fn ($q) => $q->whereNotNull('organization_uuid'))
            ->get()
            ->mapWithKeys(fn ($account) => [$account->id => $query->get($account, $this->showUntracked)])
            ->all();
    }
}
