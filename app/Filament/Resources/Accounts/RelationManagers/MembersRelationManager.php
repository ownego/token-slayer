<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\MembershipStatus;
use App\Exceptions\AccountConnectException;
use App\Filament\Concerns\ConnectsAccounts;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
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
 * `UsersRelationManager`/`UntrackedContributorsRelationManager` tabs.
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
     * account (landing the membership at `Pending`) or upserts them directly
     * as a `Tracked` member. Uses `syncWithoutDetaching` on the all-rows
     * `users()` relationship for the toggle-off path so it promotes an
     * existing untracked contributor (updating the pivot) or inserts a
     * brand-new member, never hitting the unique constraint. Public so
     * Filament's `{name}Action` convention can resolve it when a test or the
     * UI mounts it directly.
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
                    ->required(),
                Toggle::make('provision')
                    ->label('Provision an account for this user')
                    ->default(true),
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

                $started = app(AccountConnectService::class)->start();

                $livewire->replaceMountedAction('confirmProvisionMember', [
                    'userId' => $data['user_id'],
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
     * {@see AccountProvisioningService::provisionForDevice()}, granting a
     * fresh placeholder device from
     * {@see AccountProvisioningService::resolveProvisionTarget()} — which
     * writes the grant's own `token_uuid`/`provisioned_at`, not the pivot —
     * then upserts the membership pivot as `Pending` so a freshly-provisioned
     * member isn't counted as verified until they complete setup. Mirrors
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

                try {
                    // Wrapped so a throw from provisionForDevice() (bad/expired
                    // code) rolls back the device insert too — otherwise a
                    // failed paste leaves an orphan placeholder device behind.
                    DB::transaction(function () use ($service, $user, $account, $data): void {
                        $device = $service->resolveProvisionTarget($user, null);
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

                $account->users()->syncWithoutDetaching([
                    $user->id => ['status' => MembershipStatus::Pending->value],
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()
                    ->success()
                    ->title('Member added')
                    ->body("Provisioned {$user->displayHandle()} and added them as pending.")
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
}
