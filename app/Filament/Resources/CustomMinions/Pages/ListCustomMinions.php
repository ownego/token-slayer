<?php

namespace App\Filament\Resources\CustomMinions\Pages;

use App\Filament\Resources\CustomMinions\CustomMinionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The `CustomMinion` index page: the only page this resource registers.
 */
class ListCustomMinions extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<CustomMinionResource>
     */
    protected static string $resource = CustomMinionResource::class;
}
