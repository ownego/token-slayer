<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMembershipCache;
use App\Support\CacheKeys;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Developers who have events attributed to this `Account` but are not tracked
 * members (`account_user.status = untracked`). Surfaces them so an admin can
 * promote them to tracking. Rows come from the `untrackedUsers` relationship;
 * the per-user event count and last-seen come from the cached
 * {@see AccountMembershipCache}, dropped on promote/refresh.
 */
class UntrackedContributorsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'untrackedUsers';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Untracked contributors';

    /**
     * No form: this tab only lists and promotes.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the untracked-contributors table: identity columns plus the cached
     * per-account event count and last-seen, an ⋯-grouped promote row action,
     * and a Refresh header action.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        $aggregates = $this->aggregates();

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('User')
                    ->state(fn (User $record): string => $record->displayHandle())
                    ->searchable(['name', 'slack_handle', 'display_name']),
                TextColumn::make('email')->searchable(),
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
                $this->refreshAction(),
            ])
            ->recordActions([
                ActionGroup::make([
                    $this->promoteAction(),
                ]),
            ]);
    }

    /**
     * The cached per-account untracked aggregates, keyed by user id.
     *
     * @return array<int, array{events:int, last_seen:?string}>
     */
    private function aggregates(): array
    {
        /** @var Account $account */
        $account = $this->getOwnerRecord();

        return app(AccountMembershipCache::class)->untrackedAggregates($account);
    }

    /**
     * Build the "Add to tracking" row action: flips the pivot status to tracked
     * and forgets the membership caches so both tabs rebuild.
     *
     * @return Action
     */
    private function promoteAction(): Action
    {
        return Action::make('attach')
            ->label('Add to tracking')
            ->icon(Heroicon::OutlinedUserPlus)
            ->action(function (User $record): void {
                /** @var Account $account */
                $account = $this->getOwnerRecord();
                $account->untrackedUsers()->updateExistingPivot($record->id, [
                    'status' => MembershipStatus::Tracked->value,
                ]);
                CacheKeys::forgetAccountMembership($account->id);

                Notification::make()->success()->title('Added to tracking')->send();
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
