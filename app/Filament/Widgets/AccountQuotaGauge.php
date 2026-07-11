<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Services\Analytics\QuotaGaugesQuery;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;

/**
 * Blade widget showing the current quota gauge card for one account: 5h and
 * 7d utilization bars, time-to-reset, projected utilization at reset, and a
 * near-cap flag. Record-aware: rendered on the account's
 * {@see ViewAccount} page.
 */
class AccountQuotaGauge extends Widget
{
    /**
     * The account record this widget belongs to, injected by the view page.
     *
     * @var Model|null
     */
    public ?Model $record = null;

    /**
     * The Blade view rendering the gauge card.
     *
     * @var string
     */
    protected string $view = 'filament.widgets.account-quota-gauge';

    /**
     * How many of the page's columns this widget spans.
     *
     * @var int|string|array<string, int|string|null>
     */
    protected int|string|array $columnSpan = 'full';

    /**
     * Provide this account's gauge row to the view, or null when the
     * account has no gauge row (e.g. it was deleted after page load).
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        if ($this->record === null) {
            return ['gauge' => null];
        }

        $gauge = collect(app(QuotaGaugesQuery::class)->get())
            ->firstWhere('account_id', $this->record->id);

        return ['gauge' => $gauge];
    }
}
