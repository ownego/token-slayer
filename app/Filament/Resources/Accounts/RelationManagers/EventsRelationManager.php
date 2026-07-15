<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use App\Models\Event;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only stream of the events attributed to an `Account`
 * (`events.account_id`), newest first. Shows which developer logged each
 * event, its provider, token cost, and session — no create/edit/delete.
 */
class EventsRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager reads.
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('developer')
                    ->label('Developer')
                    ->state(fn (Event $record): string => $record->user?->displayHandle() ?? '#'.$record->user_id),
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
