<?php

namespace App\Filament\Pages;

use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Services\AccountConnectService;
use App\Services\Accounts\ExpiringAccountsQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin page listing accounts whose refresh-token deadline is within the
 * warning window ({@see ExpiringAccountsQuery}), so an admin can proactively
 * reconnect the account (via this page's own row-scoped {@see
 * reconnectAction()}) before a user's session breaks. Deliberately a
 * separate page from {@see UnrecognizedAccounts} — that page solves
 * attribution gaps, this one solves credential health, and combining them
 * would confuse the two concerns. Access is gated the same way as
 * `UnrecognizedAccounts`: panel access plus the `view_usage_analytics`
 * permission.
 */
class ExpiringAccounts extends Page
{
    /**
     * Only users granted the usage-analytics permission may open this page.
     * super_admin passes via filament-shield's Gate::before bypass.
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
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

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
    protected static ?string $navigationLabel = 'Expiring Accounts';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Expiring Accounts';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.expiring-accounts';

    /**
     * The count of accounts within the warning window, shown as a sidebar
     * badge so an admin sees urgency without opening the page.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        // Uses the lightweight ExpiringAccountsQuery::count() (a plain
        // COUNT(*), no withCount subquery or row hydration) rather than
        // get() -- Filament computes navigation badges for every registered
        // page on every panel render, not only when viewing this page, so
        // the cheap query matters here more than it would on a page-specific
        // action.
        $count = app(ExpiringAccountsQuery::class)->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * Badge color — red, matching the urgency this page exists to surface.
     *
     * @return string|array<int, string>|null
     */
    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'danger';
    }

    /**
     * The expiring-account rows for the Blade view.
     *
     * @return array<int, array{id: int, email: ?string, plan: ?string, expires_at: \Illuminate\Support\Carbon, tracked_members: int, updated_at: ?\Illuminate\Support\Carbon}>
     */
    public function rows(): array
    {
        return app(ExpiringAccountsQuery::class)->get();
    }

    /**
     * Row-scoped "Reconnect": a fresh PKCE attempt scoped to the SPECIFIC
     * account passed as the `account` mount argument (the row's own id) —
     * unlike {@see \App\Filament\Concerns\ConnectsAccounts::connectAccountAction()},
     * which has no target and would silently update whichever account the
     * authorized identity happens to match. Passes that `Account` as
     * `AccountConnectService::resolve()`'s `$expected` argument (its
     * existing "per-row re-auth" mode), so an admin who authorizes a
     * DIFFERENT Claude account's identity gets `connect_identity_mismatch`
     * instead of a silent wrong-account update. No "create new account"
     * branch is needed here (unlike the open-connect flow) — every row is
     * already a known, existing account.
     *
     * @return Action
     */
    public function reconnectAction(): Action
    {
        return Action::make('reconnect')
            ->label('Reconnect')
            ->modalHeading('Reconnect this account')
            ->modalDescription('Open the authorize URL, log in as THIS account, approve, then paste the code back here.')
            ->modalSubmitActionLabel('Continue')
            ->fillForm(function (): array {
                $started = app(AccountConnectService::class)->start();

                return [
                    'authorize_url' => $started['url'],
                    'state' => $started['state'],
                    'code' => '',
                ];
            })
            ->schema([
                TextInput::make('authorize_url')
                    ->label('Authorize URL')
                    ->readOnly()
                    ->copyable(),
                Hidden::make('state'),
                TextInput::make('code')
                    ->label('Paste the code here')
                    ->required(),
            ])
            ->action(function (array $data, array $arguments): void {
                $account = Account::find($arguments['account'] ?? null);
                if ($account === null) {
                    Notification::make()
                        ->danger()
                        ->title('Reconnect failed')
                        ->body('This account no longer exists.')
                        ->send();

                    return;
                }

                try {
                    $resolution = app(AccountConnectService::class)->resolve($data['state'], $data['code'], $account);
                } catch (AccountConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Reconnect failed')
                        ->body(match ($exception->reason) {
                            'connect_state_expired' => 'This connect link expired or was already used. Start again.',
                            'connect_identity_mismatch' => $exception->getMessage(),
                            'connect_no_identity' => 'Could not read an email from the authorized Claude account.',
                            default => 'Something went wrong completing the reconnect.',
                        })
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Token updated')
                    ->body("Updated the token for {$resolution->account->email}.")
                    ->send();
            });
    }
}
