<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin management of `User` roles: users self-register via Slack OAuth
 * (no create/delete here), this resource only exposes editing the roles
 * assigned to an existing user — the sole UI surface for granting/revoking
 * admin-panel access and its Shield-generated permissions.
 */
class UserResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<User>|null
     */
    protected static ?string $model = User::class;

    /**
     * Sidebar navigation icon for this resource.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /**
     * Build the edit form: a single multi-select of roles, sourced from the
     * `roles` table via the `HasRoles` relation.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('roles')
                ->relationship('roles', 'name')
                ->multiple()
                ->preload()
                ->helperText('Roles granted to this user. Granting any role gives access to the admin panel; which Resources/actions they can use inside it is governed per-permission by that role.'),
        ]);
    }

    /**
     * Build the index table: identity, and the roles currently assigned.
     *
     * @param  Table  $table  the table being configured by Filament
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Name')
                    ->getStateUsing(fn (User $record): string => $record->displayHandle())
                    ->searchable(['name', 'display_name', 'slack_handle']),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->default('—'),
                TextColumn::make('last_event_at')
                    ->label('Last active')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('name');
    }

    /**
     * Register this resource's pages: list and edit only — users self-register
     * via Slack OAuth, so there is no create/delete page here.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
