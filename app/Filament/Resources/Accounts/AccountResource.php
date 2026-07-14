<?php

namespace App\Filament\Resources\Accounts;

use App\Enums\AccountStatus;
use App\Filament\Resources\Accounts\Pages\CreateAccount;
use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\RelationManagers\UsersRelationManager;
use App\Models\Account;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin CRUD for org `Account` records: connection details, plan, status,
 * and (via `UsersRelationManager`) which `User`s are members of the account.
 * Attribution on already-ingested `events` rows is unaffected by edits or
 * deletes here — events keep the raw `account_email` they were stamped with.
 */
class AccountResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<Account>|null
     */
    protected static ?string $model = Account::class;

    /**
     * Sidebar navigation icon for this resource.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    /**
     * Build the create/edit form: email, organization UUID (auto-learned
     * from events, or pasted manually for immediate attribution), display
     * name, plan, and (edit-only) the connection status.
     *
     * @param  Schema  $schema  The schema being configured by Filament.
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                TextInput::make('organization_uuid')
                    ->label('Organization UUID')
                    ->helperText('Auto-learned from events; paste manually to attribute switcher users immediately.')
                    ->unique(ignoreRecord: true)
                    ->maxLength(64),
                TextInput::make('name')
                    ->maxLength(255),
                TextInput::make('plan')
                    ->required()
                    ->default('max-20x')
                    ->maxLength(255),
                Select::make('status')
                    ->options(AccountStatus::class)
                    ->required()
                    ->hiddenOn('create'),
            ]);
    }

    /**
     * Build the index table: identity columns, member count, status badge,
     * and last-probed recency.
     *
     * @param  Table  $table  The table being configured by Filament.
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('plan')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Members')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('last_probed_at')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
                TextColumn::make('organization_uuid')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Deleting this account does not rewrite historical events — already-ingested events keep the raw account_email they were stamped with.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Relation managers embedded on the edit page.
     *
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    /**
     * CRUD pages registered for this resource.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAccounts::route('/'),
            'create' => CreateAccount::route('/create'),
            'edit' => EditAccount::route('/{record}/edit'),
        ];
    }
}
