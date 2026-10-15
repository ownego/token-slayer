<?php

namespace App\Filament\Resources\CustomCharacters;

use App\Exceptions\CustomCharacterBusyException;
use App\Filament\Resources\CustomCharacters\Pages\ListCustomCharacters;
use App\Models\CustomCharacter;
use App\Services\CustomCharacter\RemoveCustomCharacter;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The players' custom fighters, for the people who may take one down. There is
 * no create or edit surface (players make and manage their own in the fighter
 * sheet): the list shows who has what, and the only action is removing a
 * fighter, which falls the player back to the roster character they had
 * equipped and drops the fighter from every client's field live.
 */
class CustomCharacterResource extends Resource
{
    /**
     * The Eloquent model this resource manages.
     *
     * @var class-string<CustomCharacter>|null
     */
    protected static ?string $model = CustomCharacter::class;

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

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
        return 'Custom fighters';
    }

    /**
     * Builds the table: who owns each fighter, its name, whether it is the one
     * equipped, how many versions it has, and the row action that removes it.
     *
     * @param  Table  $table  the table being configured
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user')->withCount('versions'))
            ->columns([
                TextColumn::make('user.slack_handle')
                    ->label('Player')
                    ->state(fn (CustomCharacter $record): string => $record->user->displayHandle())
                    ->searchable(['users.slack_handle', 'users.display_name', 'users.name'], isIndividual: false, isGlobal: false)
                    ->sortable(false),
                TextColumn::make('name')->label('Fighter')->searchable()->sortable(),
                IconColumn::make('active')->label('Equipped')->boolean()->sortable(),
                TextColumn::make('versions_count')->label('Versions')->numeric()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->since()->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The fighter and every version of it are deleted. The player goes back to the roster character they had equipped.')
                    ->authorize('delete')
                    ->action(function (CustomCharacter $record): void {
                        try {
                            app(RemoveCustomCharacter::class)->execute($record);
                        } catch (CustomCharacterBusyException $exception) {
                            Notification::make()->title($exception->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title('Fighter removed')->success()->send();
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
            'index' => ListCustomCharacters::route('/'),
        ];
    }
}
