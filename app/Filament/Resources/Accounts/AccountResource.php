<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\AccountStatus;
use App\Exceptions\AccountConnectException;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\ProvisionsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\UntrackedContributorsRelationManager;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Services\AccountConnectService;
use App\Services\UsageProber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin CRUD for org `Account` records: connection details, plan, status,
 * and (via `UsersRelationManager`) which `User`s are members of the account.
 * Attribution on already-ingested `events` rows is unaffected by edits or
 * deletes here — events keep the raw `account_email` they were stamped with.
 */
class AccountResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<Account>|null
     */
    protected static ?string $model = Account::class;

    /**
     * Sidebar navigation icon for this resource.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * Build the create/edit form: email, organization UUID (auto-learned
     * from events, or pasted manually for immediate attribution), display
     * name, plan, and (edit-only) the connection status.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255)
                    ->disabledOn('edit'),
                TextInput::make('organization_uuid')
                    ->label('Organization UUID')
                    ->helperText('Auto-learned from events; paste manually to attribute switcher users immediately.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(64)
                    ->disabledOn('edit'),
                TextInput::make('name')
                    ->maxLength(255),
                TextInput::make('plan')
                    ->required()
                    ->default('max-20x')
                    ->maxLength(255)
                    ->helperText('From Claude (organization type).')
                    ->disabledOn('edit'),
                Select::make('status')
                    ->options(AccountStatus::class)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }

    /**
     * Build the index table: identity columns, member count, status badge,
     * and last-probed recency.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('plan')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tracked_users_count')
                    ->counts('trackedUsers')
                    ->label('Members')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('latestUsageSnapshot.util_5h')
                    ->label('5h')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state}%")
                    ->color(fn (?int $state): string => static::utilizationColor($state)),
                TextColumn::make('latestUsageSnapshot.util_7d')
                    ->label('7d')
                    ->badge()
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "{$state}%")
                    ->color(fn (?int $state): string => static::utilizationColor($state)),
                TextColumn::make('last_probed_at')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('organization_uuid')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ActionGroup::make([
                    static::connectAction(),
                    static::refreshNowAction(),
                    static::disconnectAction(),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                        ->modalDescription('Deleting this account does not rewrite historical events — already-ingested events keep the raw account_email they were stamped with.'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Build the per-row "Connect" record action, used to re-auth a specific
     * account. Only shown when the account is not already Active. Mounting
     * starts a fresh {@see AccountConnectService} attempt and shows the
     * authorize URL; submitting resolves the pasted code against THIS record
     * ({@see AccountConnectService::resolve()} with the record as the expected
     * account), so authorizing a different Claude account is rejected and
     * writes nothing.
     *
     * @return Action
     */
    private static function connectAction(): Action
    {
        return Action::make('connect')
            ->label('Connect')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Account $record): bool => $record->status !== AccountStatus::Active)
            ->modalHeading('Re-connect Claude account')
            ->modalDescription('Open the authorize URL, approve access, then paste the code back here. You must authorize the same account this row represents.')
            ->modalSubmitActionLabel('Complete connect')
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
            ->action(function (array $data, Account $record): void {
                try {
                    app(AccountConnectService::class)->resolve($data['state'], $data['code'], $record);
                } catch (AccountConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Connect failed')
                        ->body(match ($exception->reason) {
                            'connect_identity_mismatch' => 'The Claude account you authorized does not match this account.',
                            'connect_state_expired' => 'This connect link expired or was already used. Click Connect to start again.',
                            'connect_no_identity' => 'Could not read an email from the authorized Claude account.',
                            default => 'Something went wrong completing the connect.',
                        })
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Account re-connected')
                    ->send();
            });
    }

    /**
     * Build the "Refresh now" record action: runs the usage prober against the
     * account on demand and reports the fresh 5h/7d utilization, or the recorded
     * probe error. Delegates all work to {@see UsageProber}.
     *
     * @return Action
     */
    private static function refreshNowAction(): Action
    {
        return Action::make('refreshNow')
            ->label('Refresh now')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (Account $record): void {
                $snapshot = app(UsageProber::class)->probe($record);

                if ($snapshot === null) {
                    Notification::make()
                        ->warning()
                        ->title('Probe did not complete')
                        ->body($record->refresh()->probe_error ?? 'The account is not probeable right now.')
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Usage refreshed')
                    ->body("5h: {$snapshot->util_5h}% · 7d: {$snapshot->util_7d}%")
                    ->send();
            });
    }

    /**
     * Build the "Disconnect" record action: the compromised-token response.
     * Wipes the stored OAuth grant via {@see AccountConnectService::disconnect()}.
     * Its confirm modal doubles as the leak runbook, because Anthropic has no
     * token-revocation endpoint — the real kill switch is owner-side.
     *
     * @return Action
     */
    private static function disconnectAction(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Disconnect Claude account')
            ->modalDescription('Wipes the stored access and refresh tokens immediately and marks the account as needing re-auth. If the token may be compromised, this alone is NOT enough — also sign into this Claude account at claude.ai and revoke app access / sign out of all sessions, then Connect again for a fresh grant.')
            ->modalSubmitActionLabel('Disconnect')
            ->action(function (Account $record): void {
                app(AccountConnectService::class)->disconnect($record);

                Notification::make()
                    ->success()
                    ->title('Account disconnected')
                    ->body('Stored tokens wiped. Revoke app access on claude.ai too if the token may be compromised.')
                    ->send();
            });
    }

    /**
     * Eager-load the latest usage snapshot so the 5h/7d quota columns don't
     * trigger a query per row.
     *
     * @return Builder<Account>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('latestUsageSnapshot');
    }

    /**
     * Map a utilization percent to a Filament badge color band: healthy
     * (&lt;70) success, warming (&lt;90) warning, hot (&ge;90) danger. Unknown
     * (null) reads as neutral gray.
     *
     * @param  ?int  $percent  the utilization percent, or null when unprobed
     * @return string the Filament color name
     */
    private static function utilizationColor(?int $percent): string
    {
        return match (true) {
            $percent === null => 'gray',
            $percent >= 90 => 'danger',
            $percent >= 70 => 'warning',
            default => 'success',
        };
    }

    /**
     * Relation managers embedded on the edit page.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
            UntrackedContributorsRelationManager::class,
            ProvisionsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    /**
     * CRUD pages registered for this resource.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
            'view' => ViewAccount::route('/{record}'),
        ];
    }
}
