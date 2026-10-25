<?php

namespace App\Filament\Pages;

use App\Services\Pricing\ModelPricesQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Read-only list of the list prices events are costed with, and of the
 * models seen without a price. Prices change only through the scheduled
 * feed sync; nothing here edits them.
 */
class ModelPrices extends Page
{
    /**
     * Only users granted the page permission may open it.
     * super_admin passes via filament-shield's Gate::before bypass.
     *
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('View:ModelPrices') ?? false;
    }

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Navigation label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Model prices';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Model prices';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.model-prices';

    /**
     * Each model's latest price for the Blade view.
     *
     * @return array<int, array{model:string, provider:string, effective_from:string, input:float, output:float, cache_read:float, cache_write_5m:float, cache_write_1h:?float}>
     */
    public function prices(): array
    {
        return app(ModelPricesQuery::class)->current();
    }

    /**
     * Models still missing a price for the Blade view.
     *
     * @return array<int, array{model:string, first_seen_at:string, last_seen_at:string}>
     */
    public function missingModels(): array
    {
        return app(ModelPricesQuery::class)->missing();
    }
}
