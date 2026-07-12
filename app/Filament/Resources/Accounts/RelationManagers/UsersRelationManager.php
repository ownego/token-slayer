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
 * The tracked members of an `Account` (`account_user.status = tracked`), with
 * each member's last event time from the cached {@see AccountMembershipCache}.
 * Members are promoted from the untracked-contributors tab; here an admin can
 * demote one ("Remove from tracking"), which flips the pivot status to
 * untracked (keeping the row, so they reappear under the untracked tab) and
 * forgets the membership caches.
 */
class UsersRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager operates on.
     *
     * @var string
     */
    protected static string $relationship = 'trackedUsers';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Users tracking';

    /**
     * No standalone form: membership status is managed through the table
     * actions below.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the members table: identity columns, the cached per-account
     * last-seen, a Refresh header action, and an ⋯-grouped demote row action.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        $lastSeen = $this->lastSeen();

        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->state(fn (User $record): string => $record->displayHandle())
                    ->searchable(['name', 'slack_handle', 'display_name']),
                TextColumn::make('email')->searchable(),
                TextColumn::make('last_seen')
                    ->label('Last seen')
                    ->state(fn (User $record): ?string => $lastSeen[$record->id] ?? null)
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->headerActions([
                $this->addMemberAction(),
                $this->refreshAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->removeFromTrackingAction(),
                ]),
            ]);
    }

    /**
     * The cached member => last-seen map for this account.
     *
     * @return array<int, ?string>
     */
    private function lastSeen(): array
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();

        return app(AccountMembershipCache::class)->trackedLastSeen($account);
    }

    /**
     * Build the "Add member" header action: selects any user and upserts them
     * onto this account as a tracked member. Uses `syncWithoutDetaching` on the
     * all-rows `users()` relationship so it promotes an existing untracked
     * contributor (updating the pivot) or inserts a brand-new member, never
     * hitting the unique constraint. Forgets the membership caches.
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
     * Build the "Remove from tracking" row action: flips the pivot status to
     * untracked (keeps the row) and forgets the membership caches.
     *
     * @return Action
     */
    private function removeFromTrackingAction(): Action
    {
        return Action::make('removeFromTracking')
            ->label('Remove from tracking')
            ->icon(Heroicon::OutlinedUserMinus)
            ->requiresConfirmation()
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
