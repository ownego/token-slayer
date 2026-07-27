<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\MembershipStatus;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use App\Models\User;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * List of the org `Account`s a `User` is a member of (`account_user` pivot,
 * any status), with the pivot's membership status and a per-account devices
 * summary. The provisioning audit trail (provisioned/claimed/revoked
 * timestamps) now lives per-grant on the Account's own Provisions tab
 * ({@see ProvisionsRelationManager}), not here. No create/edit/delete — but
 * unlike the Account side's {@see MembersRelationManager},
 * this tab does expose the same verify/unverify membership-status toggle
 * ({@see verifyAction()}/{@see unverifyAction()}), mirrored here so an
 * admin looking at a User doesn't have to hop to the Account to fix a
 * mis-tracked membership. Each row also links to the Account's own view page
 * via {@see Table::recordUrl()}.
 */
class AccountsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `User` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'accounts';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Accounts';

    /**
     * Only render this relation manager on the View page, keeping the Edit
     * page focused on role assignment.
     *
     * @param  Model  $ownerRecord  the owning User record
     * @param  string  $pageClass  the page the manager is about to render on
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewUser::class;
    }

    /**
     * No form: membership is read-only from this side.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the read-only accounts table: identity, plan, membership
     * status, and a per-account devices summary.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->recordUrl(fn (Account $record): string => AccountResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('name')
                    ->placeholder('—'),
                TextColumn::make('plan'),
                TextColumn::make('pivot.status')
                    ->label('Membership')
                    ->badge(),
                TextColumn::make('devices')
                    ->label('Devices')
                    ->state(fn (Account $record): string => AccountProvisionedGrant::deviceSummaryFor($record->id, $this->getOwnerRecord()->getKey()) ?? '—'),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->verifyAction(),
                    $this->unverifyAction(),
                ]),
            ]);
    }

    /**
     * Promote an untracked or pending membership to tracked ("verify"), from
     * the User side. Mirrors
     * {@see MembersRelationManager::verifyAction()}:
     * `$record` here is the `Account` row, so `$record->users()` IS the same
     * unfiltered relationship the sibling calls on its owner `Account` —
     * `syncWithoutDetaching()` on it (rather than a status-matched relation)
     * is required because a `pending` row sits in neither `trackedUsers()`
     * nor `untrackedUsers()`, so a status-filtered `updateExistingPivot()`
     * would silently no-op for it.
     *
     * @return Action
     */
    private function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Verify (track)')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (Account $record): bool => in_array($record->pivot->status, [MembershipStatus::Untracked, MembershipStatus::Pending], true))
            ->action(function (Account $record): void {
                /** @var User $ownerUser */
                $ownerUser = $this->getOwnerRecord();
                $record->users()->syncWithoutDetaching([
                    $ownerUser->id => ['status' => MembershipStatus::Tracked->value],
                ]);
                CacheKeys::forgetAccountMembership($record->id);

                Notification::make()->success()->title('Verified')->send();
            });
    }

    /**
     * Demote a tracked membership to untracked ("unverify"), keeping the
     * row, from the User side. Mirrors
     * {@see MembersRelationManager::unverifyAction()}:
     * `$record` here is the `Account` row, so `$record->trackedUsers()` IS
     * the same status-filtered relationship the sibling calls on its owner
     * `Account`. `trackedUsers()`'s `wherePivot('status', Tracked)` is
     * applied by `updateExistingPivot()`'s own pivot query too, so the
     * update only takes effect when the row's current status is actually
     * Tracked — matching this action's `visible()` precondition.
     *
     * @return Action
     */
    private function unverifyAction(): Action
    {
        return Action::make('unverify')
            ->label('Remove from tracking')
            ->icon(Heroicon::OutlinedUserMinus)
            ->requiresConfirmation()
            ->visible(fn (Account $record): bool => $record->pivot->status === MembershipStatus::Tracked)
            ->action(function (Account $record): void {
                /** @var User $ownerUser */
                $ownerUser = $this->getOwnerRecord();
                $record->trackedUsers()->updateExistingPivot($ownerUser->id, [
                    'status' => MembershipStatus::Untracked->value,
                ]);
                CacheKeys::forgetAccountMembership($record->id);

                Notification::make()->success()->title('Removed from tracking')->send();
            });
    }
}
