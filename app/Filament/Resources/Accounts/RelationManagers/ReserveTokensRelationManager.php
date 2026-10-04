<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\Provider;
use App\Exceptions\AccountConnectException;
use App\Exceptions\UsageProbeException;
use App\Filament\Actions\ClaudeReconnectModal;
use App\Filament\Actions\TokenSourceFields;
use App\Models\Account;
use App\Models\AccountReserveToken;
use App\Services\Provisioning\ReserveTokenService;
use App\Support\DaysLeft;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The account's pool of Claude tokens minted ahead of time
 * ({@see AccountReserveToken}). An admin adds tokens here once (authorize URL
 * + paste code); the Add member and Reissue modals can then hand one to a
 * device without pasting anything. The tab badge is the number of tokens
 * still assignable. Claude accounts only.
 */
class ReserveTokensRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account`.
     *
     * @var string
     */
    protected static string $relationship = 'reserveTokens';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Reserve';

    /**
     * Only Claude accounts have a paste-code flow, so only they get a pool.
     *
     * @param  Model  $ownerRecord  the account being viewed
     * @param  string  $pageClass  the hosting page
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Account
            && $ownerRecord->provider === Provider::Claude
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    /**
     * The tab badge: how many tokens can still be assigned, or none at zero.
     *
     * @param  Model  $ownerRecord  the account being viewed
     * @param  string  $pageClass  the hosting page
     * @return string|null
     */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = app(ReserveTokenService::class)->availableCount($ownerRecord);

        return $count > 0 ? (string) $count : null;
    }

    /**
     * No standalone form; tokens are added and discarded by the actions below.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the pool table: created, by whom, session days left, status and
     * which device a used token went to. Shows only assignable tokens unless
     * the "Available only" filter is turned off.
     *
     * @param  Table  $table  the table being configured by Filament
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['creator', 'usedGrant.device.user'])->orderBy('session_expires_at'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since(),
                TextColumn::make('creator.email')
                    ->label('Created by')
                    ->placeholder('—'),
                TextColumn::make('session')
                    ->label('Session')
                    ->state(fn (AccountReserveToken $record): string => DaysLeft::label($record->session_expires_at, $record->session_expires_at_estimated)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (AccountReserveToken $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'Available' => 'success',
                        'Used' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('used_for')
                    ->label('Used for')
                    ->state(fn (AccountReserveToken $record): ?string => $record->usedGrant === null
                        ? null
                        : $record->usedGrant->device->user->email.' · '.($record->usedGrant->device->name ?? 'Unnamed device'))
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('available')
                    ->label('Available only')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $query): Builder => $query
                            ->whereNull('used_at')
                            ->whereNull('discarded_at')
                            ->where('session_expires_at', '>', now()),
                        false: fn (Builder $query): Builder => $query,
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->headerActions([
                $this->addReserveTokenAction(),
            ])
            ->recordActions([
                $this->discardAction(),
            ]);
    }

    /**
     * The "Add reserve token" header action: authorize URL + paste code,
     * exchanged and stored by {@see ReserveTokenService::mint()}. The code
     * must authorize this account itself. Public so Filament's `{name}Action`
     * convention can resolve it when a test mounts it directly.
     *
     * @return Action
     */
    public function addReserveTokenAction(): Action
    {
        return Action::make('addReserveToken')
            ->label('Add reserve token')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a reserve token')
            ->modalDescription('Open the authorize URL, log in as this account, approve, then paste the code back here.')
            ->modalSubmitActionLabel('Add')
            ->fillForm(fn (): array => ClaudeReconnectModal::start())
            ->schema(ClaudeReconnectModal::schema())
            ->action(function (array $data): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();

                try {
                    $token = app(ReserveTokenService::class)->mint($account, auth()->user(), $data['state'], $data['code']);
                } catch (AccountConnectException|UsageProbeException $exception) {
                    TokenSourceFields::notifyFailure($exception, 'Adding the reserve token failed');

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Reserve token added')
                    ->body('Session: '.DaysLeft::label($token->session_expires_at, $token->session_expires_at_estimated))
                    ->send();
            });
    }

    /**
     * The per-row "Discard" action: takes an available token out of the
     * pool. It stays valid at Anthropic until it expires.
     *
     * @return Action
     */
    private function discardAction(): Action
    {
        return Action::make('discard')
            ->label('Discard')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Removes this token from the pool. It stays valid at Anthropic until it expires.')
            ->visible(fn (AccountReserveToken $record): bool => $record->isAvailable())
            ->action(function (AccountReserveToken $record): void {
                app(ReserveTokenService::class)->discard($record);

                Notification::make()->success()->title('Discarded')->send();
            });
    }
}
