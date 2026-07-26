<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Account;
use App\Models\AccountProvisionedGrant;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of the org `Account`s a `User` is a member of
 * (`account_user` pivot, any status), with the pivot's membership status and
 * a per-account devices summary. The provisioning audit trail
 * (provisioned/claimed/revoked timestamps) now lives per-grant on the
 * Account's own Provisions tab ({@see ProvisionsRelationManager}), not here.
 * No create/edit/delete — membership is managed from the Account side (its
 * own Members/Provisions relation managers).
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
     * Only render this relation manager on the View page, keeping the Edit
     * page focused on role assignment.
     *
     * @param  Model  $ownerRecord  the owning User record
     * @param  string  $pageClass  the page the manager is about to render on
     * @return bool
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $pageClass === ViewUser::class;
    }

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
     * status, and a per-account devices summary.
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
                TextColumn::make('devices')
                    ->label('Devices')
                    ->state(fn (Account $record): string => AccountProvisionedGrant::deviceSummaryFor($record->id, $this->getOwnerRecord()->getKey()) ?? '—'),
            ]);
    }
}
