<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\GrantStatus;
use App\Exceptions\AccountConnectException;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Per-grant OAuth provisions on this account's owner `Account`: one row per
 * {@see AccountProvisionedGrant} (device × account), not per user — a single
 * user with multiple machines shows one row per machine. Provisioning is
 * driven entirely from this tab's header/row actions ("Add device",
 * "Reissue", "Revoke", "Delete device"); the raw grant material itself is
 * NEVER shown here — it is never stored at rest, only cached encrypted with
 * a 24 h TTL until claimed.
 */
class ProvisionsRelationManager extends RelationManager
{
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['device.user', 'device.grants']))
            ->columns([
                TextColumn::make('user')
                    ->label('User')
                    ->state(fn (AccountProvisionedGrant $record): string => $record->device->user->email),
                TextColumn::make('device')
                    ->label('Device')
                    ->state(fn (AccountProvisionedGrant $record): string => $record->device->name ?? $record->device->device_id ?? 'Awaiting device')
                    ->description(fn (AccountProvisionedGrant $record): ?string => $record->device->name !== null && $record->device->device_id !== null
                        ? $record->device->device_id
                        : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (GrantStatus $state, AccountProvisionedGrant $record): string => $state === GrantStatus::Pending
                        && $record->provisioned_at->addSeconds(CacheKeys::PROVISIONED_GRANT_TTL_SECONDS)->isPast()
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
            ])
            ->headerActions([
                $this->addDeviceAction(),
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
     * Build the "Add device" header action: pick a tracked member and either
     * an existing device of theirs or leave it blank to open a fresh
     * placeholder awaiting its first machine. Refuses to open a second
     * placeholder while one is already awaiting a machine. On success,
     * hands off to {@see confirmAddDeviceAction()} to complete the PKCE
     * exchange. Public so Filament's `{name}Action` convention can resolve
     * it when a test or the UI mounts it directly.
     *
     * @return Action
     */
    public function addDeviceAction(): Action
    {
        return Action::make('addDevice')
            ->label('Add device')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->modalSubmitActionLabel('Continue')
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => $this->memberOptions())
                    ->searchable()
                    ->live()
                    ->required(),
                Select::make('device_pk')
                    ->label('Device')
                    ->options(fn (Get $get): array => $this->deviceOptions($get('user_id')))
                    ->placeholder('New device')
                    ->helperText('Leave empty to open a door for a brand-new machine.'),
                TextInput::make('device_name')
                    ->label('Device name')
                    ->maxLength(50)
                    ->helperText('Only used when creating a new device.'),
            ])
            ->action(function (array $data, Component $livewire): void {
                $user = User::query()->findOrFail($data['user_id']);

                if (($data['device_pk'] ?? null) === null && $user->devices()->whereNull('device_id')->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('A placeholder is already awaiting a machine')
                        ->body('Reuse the open "Awaiting device" slot (select it) instead of opening a second door.')
                        ->send();

                    return;
                }

                $started = app(AccountConnectService::class)->start();

                $livewire->replaceMountedAction('confirmAddDevice', [
                    'userId' => $user->id,
                    'devicePk' => $data['device_pk'] ?? null,
                    'deviceName' => $data['device_name'] ?? null,
                    'authorizeUrl' => $started['url'],
                    'state' => $started['state'],
                ]);
            });
    }

    /**
     * The follow-up "paste the code" modal for {@see addDeviceAction()},
     * mounted by name via `replaceMountedAction()`. Resolved on demand by
     * Filament's `{name}Action` method convention (never rendered as its own
     * button). Mirrors `MembersRelationManager::confirmProvisionMemberAction()`.
     *
     * @return Action
     */
    public function confirmAddDeviceAction(): Action
    {
        return Action::make('confirmAddDevice')
            ->modalHeading('Provision a device')
            ->modalDescription('Open the authorize URL, log in as the account to grant, approve, then paste the code back here.')
            ->modalSubmitActionLabel('Provision')
            ->fillForm(fn (array $arguments): array => [
                'authorize_url' => $arguments['authorizeUrl'] ?? '',
                'state' => $arguments['state'] ?? '',
                'code' => '',
            ])
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
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $user = User::query()->findOrFail($arguments['userId']);
                $service = app(AccountProvisioningService::class);

                try {
                    // Wrapped so a throw from provisionForDevice() (bad/expired
                    // code) rolls back the device insert too — otherwise a
                    // failed paste leaves an orphan placeholder device behind.
                    DB::transaction(function () use ($service, $user, $account, $arguments, $data): void {
                        $device = $service->resolveProvisionTarget($user, $arguments['devicePk'], $arguments['deviceName'] ?? null);
                        $service->provisionForDevice($user, $account, $device, $data['state'], $data['code']);
                    });
                } catch (AccountConnectException $exception) {
                    $this->notifyConnectFailure($exception, 'provisioning');

                    return;
                }

                Notification::make()->success()->title('Device provisioned')->send();
            });
    }

    /**
     * Build the "Reissue" row action: starts a fresh PKCE connect attempt for
     * the same device the grant is on and hands off to
     * {@see confirmReissueAction()} to complete it. The old grant is revoked
     * as part of the exchange via
     * {@see AccountProvisioningService::provisionForDevice()}'s one-live-grant
     * invariant.
     *
     * @return Action
     */
    private function reissueAction(): Action
    {
        return Action::make('reissue')
            ->label('Reissue')
            ->icon(Heroicon::OutlinedArrowPath)
            ->visible(fn (AccountProvisionedGrant $record): bool => $record->status !== GrantStatus::Revoked)
            ->action(function (AccountProvisionedGrant $record, Component $livewire): void {
                $started = app(AccountConnectService::class)->start();

                $livewire->replaceMountedAction('confirmReissue', [
                    'grantId' => $record->id,
                    'authorizeUrl' => $started['url'],
                    'state' => $started['state'],
                ]);
            });
    }

    /**
     * The follow-up "paste the code" modal for {@see reissueAction()},
     * mounted by name via `replaceMountedAction()`. Resolved on demand by
     * Filament's `{name}Action` method convention (never rendered as its own
     * button).
     *
     * @return Action
     */
    public function confirmReissueAction(): Action
    {
        return Action::make('confirmReissue')
            ->modalHeading('Reissue this grant')
            ->modalDescription('Open the authorize URL, log in as the account to grant, approve, then paste the code back here. The old grant on this device is revoked once the new one is issued.')
            ->modalSubmitActionLabel('Reissue')
            ->fillForm(fn (array $arguments): array => [
                'authorize_url' => $arguments['authorizeUrl'] ?? '',
                'state' => $arguments['state'] ?? '',
                'code' => '',
            ])
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
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $grant = AccountProvisionedGrant::query()->findOrFail($arguments['grantId']);
                $service = app(AccountProvisioningService::class);

                try {
                    $service->provisionForDevice($grant->device->user, $account, $grant->device, $data['state'], $data['code']);
                } catch (AccountConnectException $exception) {
                    $this->notifyConnectFailure($exception, 'reissue');

                    return;
                }

                Notification::make()->success()->title('Grant reissued')->send();
            });
    }

    /**
     * Build the "Revoke" row action: soft-revokes the grant and forgets the
     * cached secret via {@see AccountProvisioningService::revoke()}. Hidden
     * once a row is already revoked.
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
            ->modalDescription('Marks this grant revoked and forgets the cached secret so it cannot be claimed. A grant already handed to the client must be deleted separately at claude.ai using its token_uuid.')
            ->modalSubmitActionLabel('Revoke')
            ->visible(fn (AccountProvisionedGrant $record): bool => $record->status !== GrantStatus::Revoked)
            ->action(function (AccountProvisionedGrant $record): void {
                app(AccountProvisioningService::class)->revoke($record);

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

    /**
     * Show the shared "connect failed" notification for
     * {@see confirmAddDeviceAction()} and {@see confirmReissueAction()}: both
     * exchange a pasted PKCE code via
     * {@see AccountProvisioningService::provisionForDevice()} and differ only
     * in what to call the failing step.
     *
     * @param  AccountConnectException  $exception  the connect failure raised mid-exchange
     * @param  string  $action  the failing step's label, e.g. 'provisioning' or 'reissue' — used in the title and default body
     * @return void
     */
    private function notifyConnectFailure(AccountConnectException $exception, string $action): void
    {
        Notification::make()
            ->danger()
            ->title(ucfirst($action).' failed')
            ->body(match ($exception->reason) {
                'connect_identity_mismatch' => $exception->getMessage(),
                'connect_state_expired' => 'This connect link expired or was already used. Start again.',
                default => "Something went wrong completing the {$action}.",
            })
            ->send();
    }

    /**
     * The owner account's tracked members as `[id => email]`, for the "Add
     * device" user select.
     *
     * @return array<int, string>
     */
    private function memberOptions(): array
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();

        return $account->trackedUsers()->orderBy('email')->pluck('email', 'users.id')->all();
    }

    /**
     * The selected user's devices as `[id => label]`, for the "Add device"
     * device select. Labeled by name, falling back to the fingerprint, then
     * to a per-id placeholder label when neither is set yet.
     *
     * @param  int|string|null  $userId  the selected user id, or null before one is chosen
     * @return array<int, string>
     */
    private function deviceOptions(int|string|null $userId): array
    {
        if ($userId === null || $userId === '') {
            return [];
        }

        $user = User::query()->find($userId);
        if ($user === null) {
            return [];
        }

        return $user->devices()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($device): array => [
                $device->id => $device->name ?? $device->device_id ?? 'Awaiting device #'.$device->id,
            ])
            ->all();
    }
}
