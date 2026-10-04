<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\GrantStatus;
use App\Enums\Provider;
use App\Filament\Concerns\ReissuesGrants;
use App\Models\AccountProvisionedGrant;
use App\Services\AccountProvisioningService;
use App\Services\ProviderServiceFactory;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * Per-grant OAuth provisions on this account's owner `Account`: one row per
 * {@see AccountProvisionedGrant} (device × account), not per user — a single
 * user with multiple machines shows one row per machine. Provisioning a new
 * device happens ONLY through `MembersRelationManager`'s "Add member" flow
 * (see {@see MembersRelationManager::addMemberAction()});
 * this tab drives only the per-grant row actions ("Reissue", "Revoke",
 * "Delete device") over rows created there. The raw grant material itself is
 * NEVER shown here — it is never stored at rest, only cached encrypted with
 * a 24 h TTL until claimed.
 */
class ProvisionsRelationManager extends RelationManager
{
    use ReissuesGrants;

    /**
     * The relationship on the owner `Account` this manager reads: one row
     * per grant (device × user), not per user.
     *
     * @var string
     */
    protected static string $relationship = 'provisionedGrants';

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
     * Build the provisions table: one row per grant, showing the holding
     * user's email, the device fingerprint (or "Awaiting device" for a
     * placeholder), a status badge (surfacing TTL expiry on Pending rows),
     * the lifecycle timestamps, and the handed-off grant's token_uuid (an
     * opaque reference, not a secret — no token value is ever stored or
     * shown). Eager-loads `device.user` and `device.grants` to avoid N+1
     * across the columns and the delete-device visibility check.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['device.user', 'device.grants', 'account']))
            ->columns([
                TextColumn::make('user')
                    ->label('User')
                    ->state(fn (AccountProvisionedGrant $record): string => $record->device->user->email),
                TextColumn::make('device')
                    ->label('Device')
                    ->state(fn (AccountProvisionedGrant $record): string => $record->device->name
                        ?? ($record->device->device_id !== null ? 'Unnamed device' : 'Awaiting device'))
                    ->tooltip(fn (AccountProvisionedGrant $record): ?string => $record->device->device_id),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (GrantStatus $state, AccountProvisionedGrant $record): string => $state === GrantStatus::Pending
                        && $record->provisioned_at->addSeconds(CacheKeys::PROVISIONED_GRANT_PENDING_BADGE_SECONDS)->isPast()
                            ? 'Pending (expired)'
                            : $state->getLabel()),
                TextColumn::make('provisioned_at')
                    ->label('Provisioned')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('claimed_at')
                    ->label('Claimed')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('revoked_at')
                    ->label('Revoked')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('deprovisioned_at')
                    ->label('Deprovisioned')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('token_uuid')
                    ->label('Token UUID')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
                TextColumn::make('account.provider')
                    ->label('Provider')
                    ->badge()
                    ->toggleable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->reissueAction(),
                    $this->revokeAction(),
                    $this->deleteDeviceAction(),
                ]),
            ]);
    }

    /**
     * Build the "Reissue" row action: hands off to the shared
     * {@see ReissuesGrants::confirmReissueAction()} modal (reserve token or
     * pasted code) for the same device the grant is on. The old grant is
     * revoked by {@see AccountProvisioningService::issueGrant()}'s
     * one-live-grant invariant.
     *
     * @return Action
     */
    private function reissueAction(): Action
    {
        return Action::make('reissue')
            ->label('Reissue')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (AccountProvisionedGrant $record): bool => $record->status !== GrantStatus::Revoked
                && $record->account->provider === Provider::Claude)
            ->action(fn (AccountProvisionedGrant $record, Component $livewire) => $this->startReissue($record->id, $livewire));
    }

    /**
     * Build the "Revoke" row action: soft-revokes the grant and forgets the
     * cached secret, via the provider's revoker
     * ({@see ProviderServiceFactory::revokerFor()}) — Codex's additionally
     * calls the real OpenAI revoke endpoint first. Hidden once a row is
     * already revoked.
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
            ->modalHeading('Revoke grant')
            ->modalDescription(fn (AccountProvisionedGrant $record): string => $record->account->provider === Provider::Codex
                ? 'Marks this grant revoked, calls OpenAI to revoke the refresh token, and forgets the cached secret.'
                : 'Marks this grant revoked and forgets the cached secret so it cannot be claimed. A grant already handed to the client must be deleted separately at claude.ai using its token_uuid.')
            ->modalSubmitActionLabel('Revoke')
            ->visible(fn (AccountProvisionedGrant $record): bool => $record->status !== GrantStatus::Revoked)
            ->action(function (AccountProvisionedGrant $record): void {
                app(ProviderServiceFactory::class)->revokerFor($record->account)->revoke($record);

                Notification::make()->success()->title('Grant revoked')->send();
            });
    }

    /**
     * Delete an orphaned device (a wiped machine): allowed only once every
     * grant on it is revoked; the FK cascade removes its grant history.
     * Reads the visibility check off the `device.grants` relation eager-loaded
     * by the `table()` method above rather than issuing a fresh query per row.
     *
     * @return Action
     */
    private function deleteDeviceAction(): Action
    {
        return Action::make('deleteDevice')
            ->label('Delete device')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Removes this machine and its grant history. Only possible when no live grant remains on it.')
            ->visible(fn (AccountProvisionedGrant $record): bool => $record->device->grants
                ->doesntContain(fn (AccountProvisionedGrant $grant): bool => $grant->status !== GrantStatus::Revoked))
            ->action(function (AccountProvisionedGrant $record): void {
                $record->device->delete();

                Notification::make()->success()->title('Device deleted')->send();
            });
    }
}
