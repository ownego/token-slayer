<?php

namespace App\Filament\Resources\CustomCharacters\Pages;

use App\Filament\Resources\CustomCharacters\CustomCharacterResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The `CustomCharacter` index page: the only page this resource registers.
 */
class ListCustomCharacters extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<CustomCharacterResource>
     */
    protected static string $resource = CustomCharacterResource::class;
}
