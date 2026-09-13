<?php

namespace App\Filament\Pages;

use App\Enums\MembershipStatus;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * The admin panel home dashboard, extended with a shared filter form: a time
 * range (today/this week/this month/all/custom, defaulting to this week) and
 * a token mode. The "total across accounts" and "show untracked
 * contributors" toggles live behind the "Display options" header action
 * instead — few admins touch them, so they stay out of the always-visible
 * row. Filter-aware widgets — chiefly the Fleet Quota member breakdown —
 * read all of these via `InteractsWithPageFilters`.
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
                Placeholder::make('total_active_users')
                    ->label('Total active users')
                    ->content(new HtmlString('<span style="font-size:1.75rem; font-weight:600; line-height:1;">'.$this->totalActiveUsersCount().'</span>'))
                    ->columnSpan(1),
                Select::make('range')
                    ->options([
                        'today' => 'Today',
                        'week' => 'This week',
                        'month' => 'This month',
                        'all' => 'All time',
                        'custom' => 'Custom range',
                    ])
                    ->default('week')
                    ->live()
                    ->columnSpan(1),
                DatePicker::make('from')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                DatePicker::make('to')->visible(fn (callable $get): bool => $get('range') === 'custom'),
                Select::make('token_mode')
                    ->label('Tokens')
                    ->options([
                        'output' => 'Output tokens',
                        'total' => 'Total tokens',
                        'quota' => 'Quota tokens',
                    ])
                    ->default('output')
                    // Filament persists this form to the session
                    // (Dashboard\Concerns\HasFilters); an admin session saved
                    // before this field existed has no token_mode key at all,
                    // and fill() leaves an absent key at null rather than
                    // falling back to default() -- the select rendered blank
                    // for anyone whose session predates this filter.
                    ->afterStateHydrated(function (Select $component, ?string $state): void {
                        if ($state === null) {
                            $component->state('output');
                        }
                    })
                    ->helperText('Total = + input/cache tokens. Quota = total minus cache-read (real rate-limit cost).')
                    ->columnSpan(2),
            ]);
    }

    /**
     * The "Display options" header action: the two rarely-touched toggles
     * (total-across-accounts, show-untracked) live here instead of the
     * always-visible filter row. Reads/writes the same `$this->filters`
     * array the filter form itself uses, so widgets reading `pageFilters`
     * see no difference between the two sources.
     *
     * @return Action
     */
    public function displayOptionsAction(): Action
    {
        return Action::make('displayOptions')
            ->label('Display options')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->color('gray')
            ->modalHeading('Display options')
            ->fillForm(fn (): array => [
                'total_across_accounts' => $this->filters['total_across_accounts'] ?? false,
                'show_untracked' => $this->filters['show_untracked'] ?? false,
            ])
            ->schema([
                Toggle::make('total_across_accounts')
                    ->label('Total usage across accounts')
                    ->helperText('Off: usage attributed to this account only. On: each member\'s full usage, including other accounts and private.'),
                Toggle::make('show_untracked')
                    ->label('Show untracked contributors')
                    ->helperText('Off by default -- an untracked contributor is noise for most views.'),
            ])
            ->action(function (array $data): void {
                $this->filters = array_merge($this->filters ?? [], $data);
                $this->updatedFilters();
            });
    }

    /**
     * Header actions shown on this page.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [$this->displayOptionsAction()];
    }

    /**
     * Count of distinct users with a Tracked membership on any account, of
     * any provider — the `account_user` pivot keys off the shared envelope
     * `accounts.id`, provider-agnostic by construction, so this needs no
     * provider-conditional branching at all.
     *
     * @return int
     */
    private function totalActiveUsersCount(): int
    {
        return User::query()
            ->whereHas('accounts', fn ($query) => $query->where('account_user.status', MembershipStatus::Tracked->value))
            ->count();
    }
}
