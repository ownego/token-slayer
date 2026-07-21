<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * The admin panel home dashboard, extended with a shared filter form: a time
 * range (today/this week/this month/all/custom, defaulting to this week) and a
 * "total across accounts" toggle. Filter-aware widgets — chiefly the Fleet
 * Quota member breakdown — read these via `InteractsWithPageFilters`.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    /**
     * Build the dashboard's shared filter form. Values are exposed to widgets
     * through `$this->pageFilters`.
     *
     * @param  Schema  $schema  the filter schema being configured
     * @return Schema
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2, 'lg' => 4])
            ->components([
                Select::make('range')
                    ->options([
                        'today' => 'Today',
                        'week' => 'This week',
                        'month' => 'This month',
                        'all' => 'All time',
                        'custom' => 'Custom range',
                    ])
                    ->default('week')
                    ->live(),
                DatePicker::make('from')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                DatePicker::make('to')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                Toggle::make('total_across_accounts')
                    ->label('Total usage across accounts')
                    ->helperText(new HtmlString(
                        '<span style="display:block"><strong>Off:</strong> usage attributed to this account only.</span>'
                        .'<span style="display:block"><strong>On:</strong> each member\'s full usage, including other accounts and private.</span>'
                    )),
            ]);
    }
}
