<?php

namespace App\Filament\Resources\CustomMinions;

use App\Enums\CustomMinionSource;
use App\Exceptions\CustomCharacterBusyException;
use App\Filament\Resources\CustomMinions\Pages\ListCustomMinions;
use App\Models\CustomMinion;
use App\Services\CustomCharacter\CustomMinionStore;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\RemoveCustomMinion;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The players' custom minions, for the people who may take one down. There is no
 * create or edit surface (players build their own in the fighter sheet): the
 * list shows who has what (slot, name, whether the owner has it ticked into their pool), and
 * the only action is removing a minion, which drops it from every client's field
 * live. Viewing needs `ViewAny:CustomMinion` and removing needs `Delete:CustomMinion`.
 */
class CustomMinionResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<CustomMinion>|null
     */
    protected static ?string $model = CustomMinion::class;

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    /**
     * Navigation group this resource belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Sidebar label.
     *
     * @return string
     */
    public static function getNavigationLabel(): string
    {
        return 'Custom minions';
    }

    /**
     * Builds the table: who owns each minion, its slot and name, how it looks,
     * whether its owner has it ticked into their pool (read off the owner's `minion_pool`,
     * the owner being loaded with the rows), where it came from, and the row action that
     * removes it. The name lives in the row's JSON data, so it is shown but neither sortable
     * nor searchable, and neither is the tick, which is not a column of this table.
     *
     * @param  Table  $table  the table being configured
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('user.slack_handle')
                    ->label('Player')
                    ->state(fn (CustomMinion $record): string => $record->user->displayHandle())
                    ->searchable(['users.slack_handle', 'users.display_name', 'users.name'], isIndividual: false, isGlobal: false)
                    ->sortable(false),
                TextColumn::make('slot')->label('Slot')->numeric()->sortable(),
                TextColumn::make('name')->label('Name')->searchable(false)->sortable(false),
                ImageColumn::make('minion')
                    ->label('Minion')
                    ->state(fn (CustomMinion $record): string => url(app(CustomMinionStore::class)->stripUrls($record->user_id, $record->version_hash)['idle']))
                    ->height(40),
                IconColumn::make('ticked')
                    ->label('Ticked')
                    ->boolean()
                    ->state(fn (CustomMinion $record): bool => MinionPoolSelection::of($record->user)->slotTicked($record->slot)),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->formatStateUsing(fn (CustomMinionSource $state): string => ucfirst($state->value)),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The minion is deleted from the player\'s set. Their future subagents summon from what is left of their pool; the default minions stay.')
                    ->authorize('delete')
                    ->action(function (CustomMinion $record): void {
                        try {
                            app(RemoveCustomMinion::class)->execute($record);
                        } catch (CustomCharacterBusyException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Minion removed')->success()->send();
                    }),
            ]);
    }

    /**
     * Only the list page: there is nothing to create or edit here.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCustomMinions::route('/'),
        ];
    }
}
