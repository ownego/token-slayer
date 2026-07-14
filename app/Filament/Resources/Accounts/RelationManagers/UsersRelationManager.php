<?php

namespace App\Filament\Resources\Accounts\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Member management for an `Account`: attaches existing `User`s to the
 * `account_user` pivot (searchable by name, Slack handle, or email) and
 * detaches them. Users are never created, edited, or deleted from here —
 * this manages membership only.
 */
class UsersRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `Account` this manager operates on.
     *
     * @var string
     */
    protected static string $relationship = 'users';

    /**
     * No standalone form: membership is managed entirely through the
     * attach/detach table actions below.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the members table: identity columns plus attach (searchable by
     * name/slack_handle/email) and detach actions.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slack_handle')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->recordSelectSearchColumns(['name', 'slack_handle', 'email']),
            ])
            ->recordActions([
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
