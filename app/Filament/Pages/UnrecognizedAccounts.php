<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ConnectsAccounts;
use App\Models\User;
use App\Services\Attribution\EventAttributionBackfiller;
use App\Services\Attribution\UnattachedUsersQuery;
use App\Services\Attribution\UnrecognizedAccountsQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin page listing Anthropic org uuids that have usage events but matched no
 * `Account` at ingest (`account_id` null, `account_org_id` set), plus
 * developers with no account membership at all. The Accounts tab offers
 * Connect (via {@see ConnectsAccounts}) for orgs with no matching account yet,
 * and Backfill for orgs that now have one — the events are re-attributed in
 * place. Access is gated panel-wide by {@see User::canAccessPanel()}.
 */
class UnrecognizedAccounts extends Page
{
    use ConnectsAccounts;

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Navigation label + page title (the page manages both unrecognized
     * organizations and unattached users).
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Unrecognized';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Unrecognized';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.unrecognized-accounts';

    /**
     * Which tab is visible: 'accounts' (unrecognized organizations) or 'users'
     * (developers with no account membership).
     *
     * @var string
     */
    public string $activeTab = 'accounts';

    /**
     * The unrecognized org rows for the Blade view.
     *
     * @return array<int, array{org_uuid:string, account_id:?int, account_email:?string, events:int, tokens:int, users:int, first_seen:string, last_seen:string}>
     */
    public function rows(): array
    {
        return app(UnrecognizedAccountsQuery::class)->get();
    }

    /**
     * The unattached-user rows for the Users tab.
     *
     * @return array<int, array{user_id:int, handle:string, email:?string, last_event_at:?string, created_at:?string}>
     */
    public function unattachedUsers(): array
    {
        return app(UnattachedUsersQuery::class)->get();
    }

    /**
     * The per-org "Backfill" action, mounted by name from each Blade row with
     * an `org` argument. Attributes that org's unrecognized events to its
     * matching account and reports how many were re-attributed.
     *
     * @return Action
     */
    public function backfillAction(): Action
    {
        return Action::make('backfill')
            ->requiresConfirmation()
            ->modalHeading('Backfill unrecognized events')
            ->modalDescription('Re-attribute this organization\'s past events (that matched no account at the time) to its now-known account.')
            ->action(function (array $arguments): void {
                $org = (string) ($arguments['org'] ?? '');
                $attributed = app(EventAttributionBackfiller::class)->backfill($org);
                $count = $attributed[$org] ?? 0;

                Notification::make()
                    ->success()
                    ->title('Backfill complete')
                    ->body("Attributed {$count} events.")
                    ->send();
            });
    }
}
