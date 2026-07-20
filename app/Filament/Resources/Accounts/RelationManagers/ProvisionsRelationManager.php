<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Models\Account;
use App\Models\User;
use App\Services\AccountProvisioningService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * Per-user OAuth grants provisioned on this account's owner `Account`
 * (`account_user` pivot rows with `provisioned_at` set — see
 * {@see Account::provisionedUsers()}). Provisioning itself is
 * started from the "Add member" flow on `MembersRelationManager`; this
 * relation manager only lists the resulting grants and lets an admin Revoke
 * a row, which soft-revokes the pivot and forgets the cached grant. The raw
 * grant material itself is NEVER shown here — it is never stored at rest,
 * only cached encrypted with a 24 h TTL until claimed.
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
     * a secret — no token value is ever stored or shown), and a per-row
     * Revoke action.
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
            ->recordActions([
                ActionGroup::make([
                    $this->revokeAction(),
                ]),
            ]);
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
