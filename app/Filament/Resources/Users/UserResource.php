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
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
     * Build the index table: avatar+identity, all-time total tokens, a
     * windowed "tokens in range" figure driven by the `range` filter, and the
     * roles currently assigned. `total_tokens` and `tokens_in_range` are both
     * `withSum` aggregate aliases computed on the query, not model attributes.
     *
     * @param  Table  $table  the table being configured by Filament
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withSum('events as total_tokens', 'tokens'))
            ->filtersLayout(FiltersLayout::AboveContent)
            ->columns([
                ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (): string => 'https://ui-avatars.com/api/?name=?'),
                TextColumn::make('display_name')
                    ->label('User')
                    ->getStateUsing(fn (User $record): string => $record->displayHandle())
                    ->searchable(['name', 'display_name', 'slack_handle']),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Roles')
                    ->badge()
                    ->default('—')
                    // Who holds which role is deliberately not part of
                    // `ViewAny:User`: listing people is a broad permission,
                    // seeing their privileges is not. super_admin passes via
                    // Shield's Gate::before bypass.
                    ->visible(fn (): bool => auth()->user()?->can('ViewAny:Role') ?? false),
                TextColumn::make('total_tokens')
                    ->label('Total tokens')
                    ->numeric()
                    ->sortable()
                    ->default(0),
                TextColumn::make('tokens_in_range')
                    ->label('Tokens (range)')
                    ->numeric()
                    ->default(0)
                    ->state(fn (User $record): int => (int) ($record->tokens_in_range ?? 0)),
                TextColumn::make('account_tokens_in_range')
                    ->label('Company account usage (range)')
                    ->numeric()
                    ->default(0)
                    ->state(fn (User $record): int => (int) ($record->account_tokens_in_range ?? 0)),
                TextColumn::make('last_event_at')
                    ->label('Last active')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('range')
                    ->label('Usage window')
                    ->options(['1' => 'Today', '7' => 'Last 7 days', '30' => 'Last 30 days', '0' => 'All time'])
                    ->default('7')
                    ->selectablePlaceholder(false)
                    // A no-op `query()` closure only exists so `SelectFilter` sees a query
                    // modification callback and skips its default behaviour of treating
                    // `range` as a real column (`where('range', $value)`, which errors — there
                    // is no such column). The real aggregate is added via `baseQuery()` below.
                    ->query(fn (Builder $query): Builder => $query)
                    // `baseQuery()` (not `query()`): Filament's HasFilters::applyFiltersToTableQuery
                    // wraps `apply()`'s query in a nested `where(Closure)` for filter predicates.
                    // `withAggregate()` (which `withSum` calls) mutates the query builder's SELECT
                    // columns directly rather than via the merged `$eagerLoad` array, so an
                    // aggregate added inside that nested closure never reaches the outer query.
                    // `applyToBaseQuery()` runs unwrapped, so the aggregate lands correctly.
                    ->baseQuery(function (Builder $query, array $data): Builder {
                        $days = (int) ($data['value'] ?? 7);
                        // `days <= 0` means "All time": window sums span every event
                        // (no date floor); otherwise floor at `now() - days`.
                        $since = $days > 0 ? now()->subDays($days) : null;

                        return $query
                            ->withSum(['events as tokens_in_range' => fn (Builder $q): Builder => $since !== null ? $q->where('created_at', '>=', $since) : $q], 'tokens')
                            ->withSum(['events as account_tokens_in_range' => fn (Builder $q): Builder => $since !== null
                                ? $q->whereNotNull('account_id')->where('created_at', '>=', $since)
                                : $q->whereNotNull('account_id')], 'tokens');
                    }),
            ])
            ->defaultSort('total_tokens', 'desc');
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
