<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RepairsAccounts;
use App\Services\Attribution\ExpiringAccountsQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Admin page listing accounts an admin should look at soon: a Claude
 * account whose refresh token expires within 3 days, or a Codex account
 * whose staleness signal has tripped. See {@see ExpiringAccountsQuery} for
 * the exact predicate per provider. Access is gated by the same
 * `view_usage_analytics` permission as {@see UnrecognizedAccounts}.
 */
class ExpiringAccounts extends Page
{
    use RepairsAccounts;

    /**
     * Only users granted the usage-analytics permission may open this page.
     * super_admin passes via filament-shield's Gate::before bypass.
     *
     * @return bool
     */
    public static function canAccess(): bool
    {
        return auth()->user()?->can('view_usage_analytics') ?? false;
    }

    /**
     * Sidebar navigation icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    /**
     * Navigation group this page belongs to.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Analytics';

    /**
     * Navigation label + page title.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Expiring';

    /**
     * The page title.
     *
     * @var string|null
     */
    protected ?string $heading = 'Expiring Accounts';

    /**
     * The Blade view rendering the page body.
     *
     * @var string
     */
    protected string $view = 'filament.pages.expiring-accounts';

    /**
     * The expiring-account rows for the Blade view.
     *
     * @return array<int, array{account_id:int, email:?string, name:?string, provider:string, label:string, deadline:?Carbon}>
     */
    public function rows(): array
    {
        return app(ExpiringAccountsQuery::class)->get();
    }

    /**
     * Split counts across the rows this page lists: how many already have a
     * fresh grant out (green) versus how many have nothing live at all
     * (red) — see {@see ExpiringAccountsQuery::hasFreshPendingGrant()}. An
     * admin who reissued a grant and sees the SAME row still sitting here
     * (it stays until the account's own credential health next reports
     * clean, which is unrelated to any one grant) has no way to tell "I
     * already did this" from "I haven't yet" without this split.
     *
     * @return array{pending: int, unhandled: int}
     */
    private static function bucketCounts(): array
    {
        $rows = app(ExpiringAccountsQuery::class)->get();

        return [
            'pending' => count(array_filter($rows, fn (array $row): bool => $row['has_fresh_pending_grant'])),
            'unhandled' => count(array_filter($rows, fn (array $row): bool => ! $row['has_fresh_pending_grant'])),
        ];
    }

    /**
     * The sidebar badge: a green 🟢 count of rows already handled (a fresh
     * grant is out, just awaiting pull) and a red 🔴 count of rows with
     * nothing live yet, each spelled out short (`pending` / `unhandled`) rather
     * than left as bare numbers. A zero half is omitted rather than shown
     * as "🟢0 pending" clutter; null (no badge at all) only when both are
     * zero — a colored circle is the only way to carry two colors in one
     * Filament sidebar badge, which accepts exactly one string and one
     * color per navigation item.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        $counts = self::bucketCounts();

        $parts = array_filter([
            $counts['pending'] > 0 ? "🟢{$counts['pending']} pending" : null,
            $counts['unhandled'] > 0 ? "🔴{$counts['unhandled']} unhandled" : null,
        ]);

        return $parts === [] ? null : implode('  ', $parts);
    }

    /**
     * Spells out what the abbreviated badge means, since `pending`/`unhandled`
     * alone doesn't say what either bucket is waiting ON.
     *
     * @return string|null
     */
    public static function getNavigationBadgeTooltip(): string|Htmlable|null
    {
        $counts = self::bucketCounts();
        if ($counts['pending'] === 0 && $counts['unhandled'] === 0) {
            return null;
        }

        $lines = array_filter([
            $counts['pending'] > 0
                ? "{$counts['pending']} pending — a grant was already issued, awaiting pull"
                : null,
            $counts['unhandled'] > 0
                ? "{$counts['unhandled']} unhandled — no live grant issued yet"
                : null,
        ]);

        return implode(' · ', $lines);
    }

    /**
     * The badge pill's own color: red once anything is genuinely unhandled
     * (the more urgent bucket), green when every row already has a fresh
     * grant out. The 🟢/🔴 circles inside the text carry the per-bucket
     * color regardless — this only tints the badge itself.
     *
     * @return string|null
     */
    public static function getNavigationBadgeColor(): ?string
    {
        $counts = self::bucketCounts();
        if ($counts['unhandled'] > 0) {
            return 'danger';
        }

        return $counts['pending'] > 0 ? 'success' : null;
    }
}
