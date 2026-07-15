<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ActivityHeatmap;
use App\Filament\Widgets\FleetQuotaOverview;
use App\Filament\Widgets\TokenVolumeChart;
use App\Filament\Widgets\TopAccountsLeaderboard;
use App\Filament\Widgets\TopUsersLeaderboard;
use App\Models\Account;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Admin-only Usage Analytics page: a shared filter form (time range, account,
 * provider, user) feeding a set of consumption and quota widgets. Access is
 * already gated panel-wide by {@see User::canAccessPanel()}.
 */
class UsageAnalytics extends Page
{
    use HasFiltersForm;

    /**
     * Sidebar navigation icon for this page.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * The Blade view rendering the page body (widgets grid).
     *
     * @var string
     */
    protected string $view = 'filament.pages.usage-analytics';

    /**
     * Use the full page width so the filter row and the widgets span the
     * whole content area rather than the default capped width.
     *
     * @var Width|string|null
     */
    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * Build the shared filter form. Values are exposed to widgets via
     * `$this->filters` (read with `InteractsWithPageFilters`).
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
                        '24h' => 'Last 24 hours',
                        '7d' => 'Last 7 days',
                        '30d' => 'Last 30 days',
                        'custom' => 'Custom range',
                    ])
                    ->default('7d')
                    ->live(),
                DatePicker::make('from')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                DatePicker::make('to')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                Select::make('account_id')
                    ->label('Account')
                    ->options(fn (): array => Account::orderBy('email')->pluck('email', 'id')->all())
                    ->searchable()
                    ->placeholder('All accounts'),
                Select::make('provider')
                    ->options(['claude-code' => 'Claude Code', 'codex' => 'Codex', 'claude.ai' => 'claude.ai'])
                    ->placeholder('All providers'),
                Select::make('user_id')
                    ->label('User')
                    ->options(fn (): array => User::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->placeholder('All users'),
            ]);
    }

    /**
     * The widgets rendered below the page body — placed in the footer slot so
     * the shared filter form renders above them, not beneath.
     *
     * @return array<int, class-string>
     */
    protected function getFooterWidgets(): array
    {
        return [
            ActivityHeatmap::class,
            FleetQuotaOverview::class,
            TokenVolumeChart::class,
            TopUsersLeaderboard::class,
            TopAccountsLeaderboard::class,
        ];
    }

    /**
     * Render the footer widgets in a single column so each widget occupies a
     * full-width row.
     *
     * @return int|array<string, int|null>
     */
    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
