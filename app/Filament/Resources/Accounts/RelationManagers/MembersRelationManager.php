<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * All contributors of an `Account` in one tab, regardless of membership
 * status. Tracked members show a "Verified" badge; untracked contributors a
 * "Chưa verify" badge and can be verified (promoted) in place; a tracked
 * member can also be unverified (demoted) back to untracked. Replaces the
 * former separate `UsersRelationManager`/`UntrackedContributorsRelationManager`
 * tabs.
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
                    ->formatStateUsing(fn (MembershipStatus $state): string => $state === MembershipStatus::Tracked ? 'Verified' : 'Chưa verify')
                    ->color(fn (MembershipStatus $state): string => $state === MembershipStatus::Tracked ? 'success' : 'warning'),
                TextColumn::make('events')
                    ->label('Events')
                    ->state(fn (User $record): int => $aggregates[$record->id]['events'] ?? 0),
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->state(fn (User $record): ?string => $aggregates[$record->id]['last_seen'] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
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
     * Promote an untracked contributor to tracked ("verify"). Uses
     * `untrackedUsers()->updateExistingPivot()` so the update's `wherePivot`
     * matches the row's current (untracked) status.
     *
     * @return Action
     */
    private function verifyAction(): Action
    {
        return Action::make('verify')
            ->label('Verify (track)')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->visible(fn (User $record): bool => $record->pivot->status === MembershipStatus::Untracked)
            ->action(function (User $record): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $account->untrackedUsers()->updateExistingPivot($record->id, [
                    'status' => MembershipStatus::Tracked->value,
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
     * Build the "Add member" header action: selects any user and upserts them
     * onto this account as a tracked member. Uses `syncWithoutDetaching` on
     * the all-rows `users()` relationship so it promotes an existing
     * untracked contributor (updating the pivot) or inserts a brand-new
     * member, never hitting the unique constraint.
     *
     * @return Action
     */
    private function addMemberAction(): Action
    {
        return Action::make('addMember')
            ->label('Add member')
            ->icon(Heroicon::OutlinedUserPlus)
            ->schema([
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('email', 'id')->all())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $account->users()->syncWithoutDetaching([
                    $data['user_id'] => ['status' => MembershipStatus::Tracked->value],
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()->success()->title('Member added')->send();
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
