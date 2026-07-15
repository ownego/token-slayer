<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\AccountsRelationManager;
use App\Filament\Resources\Users\RelationManagers\EventsRelationManager;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin management of `User`s: users self-register via Slack OAuth (no
 * create/delete here). The Edit page exposes the only mutable field — roles,
 * the sole UI surface for granting/revoking admin-panel access and its
 * Shield-generated permissions. The View page is read-only: basic identity
 * plus the accounts the user belongs to and the events they've logged,
 * in relation-manager tabs.
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
     * Build the shared form/view schema: read-only basic identity (rendered
     * as `TextEntry`s on both the Edit and View pages), and the single
     * mutable field — a multi-select of roles, sourced from the `roles`
     * table via the `HasRoles` relation.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('display_handle')
                ->label('User')
                ->state(fn (User $record): string => $record->displayHandle()),
            TextEntry::make('email')
                ->state(fn (User $record): string => $record->email),
            TextEntry::make('last_event_at')
                ->label('Last active')
                ->state(fn (User $record): string => $record->last_event_at?->diffForHumans() ?? 'Never'),
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
     * Register this resource's relation managers, shown as tabs on the
     * View page: the accounts this user belongs to, and the events
     * they've logged.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            AccountsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    /**
     * Register this resource's pages: list, edit, and view — users
     * self-register via Slack OAuth, so there is no create/delete page here.
     * Row clicks on the index page land on `view` by Filament's default
     * convention.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
