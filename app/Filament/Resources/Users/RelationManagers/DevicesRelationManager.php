<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Filament\Resources\Accounts\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\Device;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Read-only list of the physical machines ({@see Device}) a `User` has been
 * provisioned on, with a "Rename" row action to relabel one. Devices are
 * created by provisioning (from the Account's Members tab — see
 * {@see MembersRelationManager::addMemberAction()})
 * and deleted from the Account's Provisions tab; neither create nor delete
 * lives here, only the name.
 */
class DevicesRelationManager extends RelationManager
{
    /**
     * The relationship on the owner `User` this manager reads.
     *
     * @var string
     */
    protected static string $relationship = 'devices';

    /**
     * The navigation/tab title for this relation.
     *
     * @var string|null
     */
    protected static ?string $title = 'Devices';

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
     * No standalone form: naming is driven entirely by the {@see renameAction()}
     * row action below.
     *
     * @param  Schema  $schema  the schema being configured by Filament
     * @return Schema
     */
    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /**
     * Build the read-only devices table: name (falling back to "Unnamed
     * device" once claimed, or "Awaiting device" while still a placeholder,
     * with the raw fingerprint tucked into a tooltip rather than shown as
     * its own column), how many grants have been issued to it, and when it
     * was added.
     *
     * @param  Table  $table  the table being configured by Filament
     * @return Table
     */
    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Device')
                    ->state(fn (Device $record): string => $record->name
                        ?? ($record->device_id !== null ? 'Unnamed device' : 'Awaiting device'))
                    ->tooltip(fn (Device $record): ?string => $record->device_id),
                TextColumn::make('grants_count')
                    ->label('Grants')
                    ->counts('grants'),
                TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime(),
            ])
            ->recordActions([
                $this->renameAction(),
            ]);
    }

    /**
     * Build the "Rename" row action: prefills the current name (blank for an
     * unnamed device) and saves the new label straight to the `Device` row.
     *
     * @return Action
     */
    private function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon(Heroicon::OutlinedPencil)
            ->fillForm(fn (Device $record): array => ['name' => $record->name])
            ->schema([
                TextInput::make('name')
                    ->label('Device name')
                    ->maxLength(50),
            ])
            ->action(function (Device $record, array $data): void {
                $record->update(['name' => $data['name']]);

                Notification::make()->success()->title('Device renamed')->send();
            });
    }
}
