<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only list of the org `Account`s a `User` is a member of
 * (`account_user` pivot, any status), with the pivot's membership status
 * and provisioning audit columns. No create/edit/delete — membership is
 * managed from the Account side (its own Users/Provisions relation
 * managers).
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
     * status, and the provisioning audit trail from the pivot.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('name')
                    ->placeholder('—'),
                TextColumn::make('plan'),
                TextColumn::make('pivot.status')
                    ->label('Membership')
                    ->badge(),
                TextColumn::make('pivot.provisioned_at')
                    ->label('Provisioned')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pivot.claimed_at')
                    ->label('Claimed')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('pivot.revoked_at')
                    ->label('Revoked')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }
}
