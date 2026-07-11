<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
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
     * Build the shared filter form. Values are exposed to widgets via
     * `$this->filters` (read with `InteractsWithPageFilters`).
     *
     * @param  Schema  $schema  the filter schema being configured
     * @return Schema
     */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
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
            ])->columns(3),
        ]);
    }

    /**
     * The header widgets rendered above the page body. Widgets registered
     * by Tasks 8–10.
     *
     * @return array<int, class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [];
    }
}
