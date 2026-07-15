<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Per-user OAuth grants provisioned on this `Account` (`account_user` pivot
 * rows with `provisioned_at` set — see {@see Account::provisionedUsers()}).
 * An admin opens the "Provision for user" flow to hand a target user a fresh
 * grant (mirrors the connect UX in ConnectsAccounts, but exchanges the code via
 * {@see AccountProvisioningService::provisionFromCode()} instead of updating
 * this account's own probe grant) and can Revoke a row, which soft-revokes
 * the pivot and forgets the cached grant. The raw grant material itself is
 * NEVER shown here — it is never stored at rest, only cached encrypted with
 * a 24 h TTL until claimed.
 */
class ProvisionsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'provisionedUsers';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Provisions';

    /**
     * No standalone form: provisioning and revocation are driven entirely by
     * the header/row actions below.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the provisions table: user identity, provisioned/claimed/revoked
     * timestamps, the handed-off grant's token_uuid (an opaque reference, not
     * a secret — no token value is ever stored or shown), a "Provision for
     * user" header action, and a per-row Revoke action.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->label('User')
                    ->searchable(),
                TextColumn::make('provisioned_at')
                    ->label('Provisioned')
                    ->state(fn (User $record): ?Carbon => $record->pivot->provisioned_at)
                    ->dateTime(),
                TextColumn::make('claimed_at')
                    ->label('Claim status')
                    ->badge()
                    ->state(fn (User $record): string => $record->pivot->claimed_at !== null ? 'Claimed' : 'Pending')
                    ->color(fn (User $record): string => $record->pivot->claimed_at !== null ? 'success' : 'warning'),
                TextColumn::make('revoked_at')
                    ->label('Revoked')
                    ->state(fn (User $record): ?Carbon => $record->pivot->revoked_at)
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('token_uuid')
                    ->label('Token UUID')
                    ->state(fn (User $record): ?string => $record->pivot->token_uuid)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->provisionForUserAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->revokeAction(),
                ]),
            ]);
    }

    /**
     * Build the "Provision for user" header action: pick a target user (only
     * users with a `hook_token`, since the grant is claimed via the hook
     * endpoint), start a fresh PKCE attempt and show the authorize URL, then
     * on submit exchange the pasted code via
     * {@see AccountProvisioningService::provisionFromCode()} for THIS
     * account. Mirrors {@see ConnectsAccounts::connectAccountAction()}.
     *
     * @return Action
     */
    private function provisionForUserAction(): Action
    {
        return Action::make('provisionForUser')
            ->label('Provision for user')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Provision a Claude account for a user')
            ->modalDescription('Pick the user, open the authorize URL, log in as this account, approve, then paste the code back here.')
            ->modalSubmitActionLabel('Provision')
            ->fillForm(function (): array {
                $started = app(AccountConnectService::class)->start();

                return [
                    'user_id' => null,
                    'authorize_url' => $started['url'],
                    'state' => $started['state'],
                    'code' => '',
                ];
            })
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::query()
                        ->whereNotNull('hook_token')
                        ->orderBy('name')
                        ->pluck('email', 'id')
                        ->all())
                    ->searchable()
                    ->required(),
                TextInput::make('authorize_url')
                    ->label('Authorize URL')
                    ->readOnly()
                    ->copyable(),
                Hidden::make('state'),
                TextInput::make('code')
                    ->label('Paste the code here')
                    ->required(),
            ])
            ->action(function (array $data): void {
                $user = User::query()->findOrFail($data['user_id']);
                /** @var Account $account */
                $account = $this->getOwnerRecord();

                try {
                    app(AccountProvisioningService::class)->provisionFromCode(
                        $user,
                        $account,
                        $data['state'],
                        $data['code'],
                    );
                } catch (AccountConnectException $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Provisioning failed')
                        ->body(match ($exception->reason) {
                            'connect_identity_mismatch' => $exception->getMessage(),
                            'connect_state_expired' => 'This connect link expired or was already used. Start again.',
                            default => 'Something went wrong completing the provisioning.',
                        })
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Provisioned')
                    ->body("Granted {$user->displayHandle()} access to this account.")
                    ->send();
            });
    }

    /**
     * Build the "Revoke" row action: soft-revokes the provision and forgets
     * the cached grant via {@see AccountProvisioningService::revoke()}.
     * Hidden once a row is already revoked.
     *
     * @return Action
     */
    private function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Revoke provision')
            ->modalDescription('Marks this provision revoked and forgets the cached grant so it cannot be claimed. A grant already handed to the client must be deleted separately at claude.ai using its token_uuid.')
            ->modalSubmitActionLabel('Revoke')
            ->visible(fn (User $record): bool => $record->pivot->revoked_at === null)
            ->action(function (User $record): void {
                app(AccountProvisioningService::class)->revoke($record->pivot);

                Notification::make()
                    ->success()
                    ->title('Provision revoked')
                    ->send();
            });
    }
}
