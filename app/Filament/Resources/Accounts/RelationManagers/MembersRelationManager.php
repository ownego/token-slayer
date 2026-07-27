<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Filament\Concerns\ConnectsAccounts;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\AccountUser;
use App\Models\User;
use App\Services\AccountConnectService;
use App\Services\AccountProvisioningService;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * All contributors of an `Account` in one tab. By default only Tracked/Pending
 * members are shown; the "Unverified members" filter (off by default) reveals
 * Untracked contributors too. Tracked members show a "Verified" badge;
 * untracked contributors an "Unverified" badge and can be verified (promoted)
 * in place; a tracked member can also be unverified (demoted) back to
 * untracked. Replaces the former separate
 * `UsersRelationManager`/`UntrackedContributorsRelationManager` tabs. This is
 * also the sole entry point for provisioning a device on the account — see
 * {@see addMemberAction()} — the standalone "Add device" action on
 * {@see ProvisionsRelationManager} was removed in its favor.
 */
class MembersRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` (all statuses).
     *
     * @var string
     */
    protected static string $relationship = 'users';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Members';

    /**
     * No standalone form; status is managed via the row actions below.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the contributors table: identity, status badge, cached event
     * count + last-seen, and verify/unverify row actions.
     *
     * @param  Table  $table  the table being configured by Filament
     * @return Table
     */
    public function table(Table $table): Table
    {
        $aggregates = $this->aggregates();

        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->state(fn (User $record): string => $record->displayHandle())
                    ->searchable(['name', 'slack_handle', 'display_name']),
                TextColumn::make('email')->searchable(),
                TextColumn::make('pivot.status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (MembershipStatus $state): string => match ($state) {
                        MembershipStatus::Tracked => 'Verified',
                        MembershipStatus::Pending => $state->getLabel(),
                        MembershipStatus::Untracked => 'Unverified',
                    })
                    ->color(fn (MembershipStatus $state): string => match ($state) {
                        MembershipStatus::Tracked => 'success',
                        MembershipStatus::Pending => 'warning',
                        MembershipStatus::Untracked => 'warning',
                    }),
                TextColumn::make('devices')
                    ->label('Devices')
                    ->state(fn (User $record): string => AccountProvisionedGrant::deviceSummaryFor($this->getOwnerRecord()->getKey(), $record->id) ?? '—'),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (User $record): int => $aggregates[$record->id]['events'] ?? 0),
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->state(fn (User $record): ?string => $aggregates[$record->id]['last_seen'] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('unverified')
                    ->label('Unverified members')
                    ->placeholder('Hidden')
                    ->trueLabel('Shown')
                    ->falseLabel('Hidden')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query,
                        false: fn (Builder $query): Builder => $query->whereIn(
                            $this->pivotStatusColumn(), [MembershipStatus::Tracked->value, MembershipStatus::Pending->value]
                        ),
                        blank: fn (Builder $query): Builder => $query->whereIn(
                            $this->pivotStatusColumn(), [MembershipStatus::Tracked->value, MembershipStatus::Pending->value]
                        ),
                    ),
            ])
            ->headerActions([
                $this->addMemberAction(),
                $this->refreshAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->verifyAction(),
                    $this->unverifyAction(),
                ]),
            ]);
    }

    /**
     * The cached per-account contributor aggregates (any status), keyed by
     * user id.
     *
     * @return array<int, array{events:int, last_seen:?string}>
     */
    private function aggregates(): array
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();

        return app(AccountMembershipCache::class)->allContributorAggregates($account);
    }

    /**
     * The qualified `status` column on the `users()` pivot table, for use in
     * filter query closures. The `Builder` those closures receive is the
     * relation's plain query (`$relationship->getQuery()`), which does NOT
     * expose `wherePivot`/`wherePivotIn` — those are methods on the
     * `BelongsToMany` relation object itself, not on its query builder, so
     * calling them here would silently fall through to Eloquent's dynamic
     * `where{Column}` magic instead of throwing. Qualifying the column with
     * the pivot table name sidesteps that trap entirely.
     *
     * @return string
     */
    private function pivotStatusColumn(): string
    {
        /** @var BelongsToMany $relationship */
        $relationship = $this->getRelationship();

        return $relationship->getTable().'.status';
    }

    /**
     * Promote an untracked or pending contributor to tracked ("verify").
     * Uses `syncWithoutDetaching()` on the base, unfiltered `users()`
     * relationship rather than a status-matched relation: a `pending` row
     * sits in neither `trackedUsers()` nor `untrackedUsers()`, so
     * `untrackedUsers()->updateExistingPivot()` would silently no-op for it
     * (the `wherePivot('status', …)` never matches, so the UPDATE affects
     * zero rows).
     *
     * @return Action
     */
    private function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Verify (track)')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (User $record): bool => in_array($record->pivot->status, [MembershipStatus::Untracked, MembershipStatus::Pending], true))
            ->action(function (User $record): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $account->users()->syncWithoutDetaching([
                    $record->id => ['status' => MembershipStatus::Tracked->value],
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()->success()->title('Verified')->send();
            });
    }

    /**
     * Demote a tracked member to untracked ("unverify"), keeping the row.
     * Uses `trackedUsers()->updateExistingPivot()` so the update's
     * `wherePivot` matches the row's current (tracked) status.
     *
     * @return Action
     */
    private function unverifyAction(): Action
    {
        return Action::make('unverify')
            ->label('Remove from tracking')
            ->icon(Heroicon::OutlinedUserMinus)
            ->requiresConfirmation()
            ->visible(fn (User $record): bool => $record->pivot->status === MembershipStatus::Tracked)
            ->action(function (User $record): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $account->trackedUsers()->updateExistingPivot($record->id, [
                    'status' => MembershipStatus::Untracked->value,
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()->success()->title('Removed from tracking')->send();
            });
    }

    /**
     * Build the "Add member" header action: selects any user and, depending
     * on the `provision` toggle (default on), either hands off to
     * {@see confirmProvisionMemberAction()} to grant them a fresh OAuth
     * account (landing the membership at `Pending`, unless already `Tracked`
     * — see {@see confirmProvisionMemberAction()}) or upserts them directly
     * as a `Tracked` member. Uses `syncWithoutDetaching` on the all-rows
     * `users()` relationship for the toggle-off path so it promotes an
     * existing untracked contributor (updating the pivot) or inserts a
     * brand-new member, never hitting the unique constraint. This is the
     * ONLY entry point for provisioning a device on this account — the
     * former standalone "Add device" action on the Provisions tab was
     * removed in favor of it. When `provision` is on, `device_pk` (reactive
     * on `user_id`, hidden entirely for a zero-device user) and
     * `device_name` let the admin target an existing device or name a fresh
     * placeholder; the one-open-door guard below refuses to open a second
     * placeholder while one already awaits a machine. Public so Filament's
     * `{name}Action` convention can resolve it when a test or the UI mounts
     * it directly.
     *
     * @return Action
     */
    public function addMemberAction(): Action
    {
        return Action::make('addMember')
            ->label('Add member')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalSubmitActionLabel('Continue')
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('email', 'id')->all())
                    ->searchable()
                    ->live()
                    ->required(),
                Toggle::make('provision')
                    ->label('Provision an account for this user')
                    ->default(true)
                    ->live(),
                Select::make('device_pk')
                    ->label('Device')
                    ->options(fn (Get $get): array => $this->deviceOptionsFor($get('user_id')))
                    ->placeholder('+ Create a new device…')
                    ->visible(fn (Get $get): bool => (bool) $get('provision') && $this->userHasDevices($get('user_id'))),
                TextInput::make('device_name')
                    ->label('Device name')
                    ->maxLength(50)
                    ->helperText('Only used when creating a new device.')
                    ->visible(fn (Get $get): bool => (bool) $get('provision')),
            ])
            ->action(function (array $data, Component $livewire): void {
                if (! $data['provision']) {
                    /** @var Account $account */
                    $account = $this->getOwnerRecord();
                    $account->users()->syncWithoutDetaching([
                        $data['user_id'] => ['status' => MembershipStatus::Tracked->value],
                    ]);
                    CacheKeys::forgetAccountMembership($account->id);

                    Notification::make()->success()->title('Member added')->send();

                    return;
                }

                $user = User::query()->findOrFail($data['user_id']);
                $devicePk = $data['device_pk'] ?? null;

                // The one-open-door guard: refuse a second placeholder while
                // one already awaits a machine, rather than letting the
                // admin lose track of which slot claims which device.
                if ($devicePk === null && $user->devices()->whereNull('device_id')->exists()) {
                    Notification::make()
                        ->danger()
                        ->title('A placeholder is already awaiting a machine')
                        ->body('Reuse the open "Awaiting device" slot (select it) instead of opening a second door.')
                        ->send();

                    return;
                }

                $started = app(AccountConnectService::class)->start();

                $livewire->replaceMountedAction('confirmProvisionMember', [
                    'userId' => $user->id,
                    'devicePk' => $devicePk,
                    'deviceName' => $data['device_name'] ?? null,
                    'authorizeUrl' => $started['url'],
                    'state' => $started['state'],
                ]);
            });
    }

    /**
     * The follow-up "provision for this member" modal, mounted by name from
     * {@see addMemberAction()} via `replaceMountedAction()` when the toggle is
     * on. Resolved on demand by Filament's `{name}Action` method convention
     * (never rendered as its own button). Exchanges the pasted code via
     * {@see AccountProvisioningService::provisionForDevice()}, granting the
     * device resolved by
     * {@see AccountProvisioningService::resolveProvisionTarget()} (an
     * existing device by id, or a fresh placeholder named from
     * `device_name`) — which writes the grant's own
     * `token_uuid`/`provisioned_at`, not the pivot.
     * `provisionForDevice()` unconditionally upserts the membership pivot to
     * `Tracked`; this reverts it to `Pending` unless the member's prior
     * status (captured before the exchange) was already `Tracked`. That
     * means a `Pending` member getting a second device STAYS `Pending` —
     * they still haven't completed setup, so a second device must not
     * silently promote them — while an already-`Tracked` member adding a
     * second machine is never demoted. Mirrors
     * {@see ConnectsAccounts::connectAccountAction()}.
     *
     * @return Action
     */
    public function confirmProvisionMemberAction(): Action
    {
        return Action::make('confirmProvisionMember')
            ->modalHeading('Provision a Claude account for this member')
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

                $priorStatus = AccountUser::query()
                    ->where('account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->first()?->status;

                try {
                    // Wrapped so a throw from provisionForDevice() (bad/expired
                    // code) rolls back the device insert too — otherwise a
                    // failed paste leaves an orphan placeholder device behind.
                    DB::transaction(function () use ($service, $user, $account, $arguments, $data): void {
                        $device = $service->resolveProvisionTarget($user, $arguments['devicePk'] ?? null, $arguments['deviceName'] ?? null);
                        $service->provisionForDevice($user, $account, $device, $data['state'], $data['code']);
                    });
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

                // Only a prior Tracked status survives; absent, Untracked,
                // AND Pending all land (or stay) at Pending — a Pending
                // member hasn't finished setup, so a second device must not
                // silently promote them via provisionForDevice()'s internal
                // Tracked upsert.
                $landsAtPending = $priorStatus !== MembershipStatus::Tracked;
                if ($landsAtPending) {
                    $account->users()->syncWithoutDetaching([
                        $user->id => ['status' => MembershipStatus::Pending->value],
                    ]);
                }
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()
                    ->success()
                    ->title('Member added')
                    ->body($landsAtPending
                        ? "Provisioned {$user->displayHandle()} and added them as pending."
                        : "Provisioned an additional device for {$user->displayHandle()}.")
                    ->send();
            });
    }

    /**
     * Build the "Refresh" header action: forgets this account's membership
     * caches so the tab re-reads from the database.
     *
     * @return Action
     */
    private function refreshAction(): Action
    {
        return Action::make('refresh')
            ->label('Refresh')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                CacheKeys::forgetAccountMembership($this->getOwnerRecord()->getKey());

                Notification::make()->success()->title('Refreshed from database')->send();
            });
    }

    /**
     * Whether the given user already has at least one device, used to decide
     * whether {@see addMemberAction()}'s `device_pk` select should appear at
     * all — a zero-device user sees only the `device_name` field, keeping
     * the form as simple as before this field existed.
     *
     * @param  int|string|null  $userId  the selected user id, or null before one is chosen
     * @return bool
     */
    private function userHasDevices(int|string|null $userId): bool
    {
        if ($userId === null || $userId === '') {
            return false;
        }

        return User::query()->find($userId)?->devices()->exists() ?? false;
    }

    /**
     * The selected user's devices as `[id => label]`, for
     * {@see addMemberAction()}'s `device_pk` select. Labeled by name,
     * falling back to the fingerprint, then to a per-id placeholder label
     * when neither is set yet.
     *
     * @param  int|string|null  $userId  the selected user id, or null before one is chosen
     * @return array<int, string>
     */
    private function deviceOptionsFor(int|string|null $userId): array
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
