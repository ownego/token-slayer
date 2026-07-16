<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only stream of the events logged by a `User` (`events.user_id`),
 * newest first, across every org account they've used. No create/edit/delete.
 */
class EventsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `User` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'events';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Events';

    /**
     * Render this relation manager only on the View page (keeping Edit focused
     * on role assignment) AND only for users granted the `view_events`
     * permission. super_admin passes via Shield's Gate::before bypass.
     *
     * @param  Model  $ownerRecord  the owning User record
     * @param  string  $pageClass  the page the manager is about to render on
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewUser::class
            && (auth()->user()?->can('view_events') ?? false);
    }

    /**
     * No form: the events stream is read-only.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the read-only events table, newest first.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('account'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('account')
                    ->label('Account')
                    ->state(fn (Event $record): string => $record->account?->email ?? $record->account_email ?? '—'),
                TextColumn::make('provider')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('tokens')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('session_id')
                    ->label('Session')
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
