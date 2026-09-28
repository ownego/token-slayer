<?php

namespace App\Livewire;

use App\Enums\FighterCharacter;
use App\Events\FighterCharacterChanged;
use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\FleetUsageRefresher;
use App\Services\BossArena;
use App\Services\DamageTotals;
use App\Services\GitHub\CachedLatestVersion;
use App\Services\Profile\AccountAlerts;
use App\Services\Profile\AccountCardSummaries;
use App\Services\Profile\AccountQuotaCards;
use App\Services\Profile\AccountRefresh;
use App\Services\Profile\BossKillCount;
use App\Services\Profile\CharacterRoommates;
use App\Services\Profile\DamageByModel;
use App\Services\Profile\DamageByPeriod;
use App\Services\Profile\HourlyDamage;
use App\Services\Profile\Period;
use App\Services\Profile\PeriodStats;
use App\Services\SubagentCountCache;
use App\Support\HookVersionStatus;
use App\Support\TopRows;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The battlefield's Profile/Character popup sheet — periods and a custom
 * damage range, per-model breakdown, an hourly bar chart, boss kills, quota
 * cards for the player's own accounts, a manual refresh, and (Character tab)
 * equipping a fighter. Embedded in the battlefield page and opened from its
 * nav — see resources/views/livewire/battlefield.blade.php.
 */
class FighterSheet extends Component
{
    /**
     * Whether the sheet is currently shown. Nothing below this line is
     * computed until it is true (see render()) — a battlefield page load
     * must never run the all-time team SUM just because this component
     * mounted alongside it.
     *
     * @var bool
     */
    public bool $open = false;

    /**
     * Which tab is showing.
     *
     * @var string
     */
    public string $tab = 'profile';

    /**
     * The active damage window: a Period value, or 'custom' once
     * applyRange() has accepted a range.
     *
     * @var string
     */
    public string $period = 'today';

    /**
     * The custom range's start (Y-m-d), kept even when applyRange() rejects it.
     *
     * @var string
     */
    public string $from = '';

    /**
     * The custom range's end (Y-m-d), kept even when applyRange() rejects it.
     *
     * @var string
     */
    public string $to = '';

    /**
     * The By-model tab's own period, independent of $period.
     *
     * @var string
     */
    public string $modelPeriod = 'today';

    /**
     * The character value currently shown as equipped: the user's explicit
     * choice if any, otherwise today's deterministic per-boss assignment —
     * ported from the old CharacterSelect, whose own docblock this repeats.
     * Used ONLY to seed the Character tab's starting selection; for the
     * true persisted equip state, see $equippedKey.
     *
     * @var string
     */
    public string $equipped = '';

    /**
     * The user's explicitly persisted equipped character, or null when the
     * user has never explicitly equipped one.
     *
     * @var string|null
     */
    public ?string $equippedKey = null;

    /**
     * Seeds $equipped/$equippedKey from the current boss context, same as
     * the old CharacterSelect::mount(), and prefills the custom range.
     *
     * @param  BossArena  $arena  supplies the current boss for the
     *                            deterministic fallback
     * @return void
     */
    public function mount(BossArena $arena): void
    {
        // the custom range opens prefilled with the last two weeks, as the mockup's does
        $this->from = CarbonImmutable::now()->subDays(13)->toDateString();
        $this->to = CarbonImmutable::now()->toDateString();

        $user = auth()->user();

        if (! $user) {
            return;
        }

        $this->equipped = $user->characterForBoss($arena->current()->id);
        $this->equippedKey = $user->equipped_character?->value;
    }

    /**
     * Opens the sheet on a given tab. Unknown tab values fall back to
     * 'profile' rather than erroring — the nav only ever sends 'profile' or
     * 'character'.
     *
     * @param  string  $tab
     * @return void
     */
    #[On('open-fighter-sheet')]
    public function open(string $tab): void
    {
        $this->open = true;
        $this->tab = in_array($tab, ['profile', 'character'], true) ? $tab : 'profile';
    }

    /**
     * Closes the sheet.
     *
     * @return void
     */
    public function close(): void
    {
        $this->open = false;
    }

    /**
     * Switches the damage period to a named window, or does nothing for an
     * unrecognized value — the tab buttons only ever send a real Period value.
     *
     * @param  string  $period
     * @return void
     */
    public function setPeriod(string $period): void
    {
        if (Period::tryFrom($period) === null) {
            return;
        }

        $this->period = $period;
    }

    /**
     * Switches the By-model tab's own period.
     *
     * @param  string  $period
     * @return void
     */
    public function setModelPeriod(string $period): void
    {
        if (Period::tryFrom($period) === null) {
            return;
        }

        $this->modelPeriod = $period;
    }

    /**
     * Validation rules for the custom range: both ends required, `to` no
     * earlier than `from`, and the whole range capped at a year — every
     * damage query this range eventually drives is a full-table SUM, so an
     * unbounded range is a real cost, not just a display nuisance.
     *
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => [
                'required', 'date', 'after_or_equal:from',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->from === '' || $value === '') {
                        return;
                    }

                    if (CarbonImmutable::parse($this->from)->diffInDays(CarbonImmutable::parse($value)) > 366) {
                        $fail('Pick a range of a year or less.');
                    }
                },
            ],
        ];
    }

    /**
     * Validates and applies the custom range, switching $period to
     * 'custom' only on success — a rejected range leaves the previous
     * period (and the entered from/to values) exactly as they were.
     *
     * @return void
     */
    public function applyRange(): void
    {
        $this->validate();
        $this->period = 'custom';
    }

    /**
     * Re-probes only this player's own probeable accounts, rate limited to
     * once a minute per user (the per-account Cache::lock inside
     * FleetUsageRefresher already covers concurrency across users sharing
     * an account). A rate-limited attempt dispatches a browser event
     * carrying the seconds left, rather than erroring.
     *
     * @param  FleetUsageRefresher  $refresher
     * @return void
     */
    public function refresh(FleetUsageRefresher $refresher): void
    {
        $user = auth()->user();
        $key = "fighter-sheet-refresh:{$user->id}";

        $ran = RateLimiter::attempt($key, 1, function () use ($refresher, $user): void {
            $accounts = $user->accounts()->probeable()->get()->merge($user->accounts()->codexProbeable()->get());
            $refresher->refreshAccounts($accounts);
        }, 60);

        if (! $ran) {
            $this->dispatch('sheet-refresh-cooldown', seconds: RateLimiter::availableIn($key));
        }
    }

    /**
     * Re-probes one account from its own card and returns the outcome for
     * the card to show ("42% → 45%", "failed", or the wait). Returned, not
     * re-rendered from: the card animates the change in place.
     *
     * @param  int  $accountId
     * @param  AccountRefresh  $refresh
     * @return array{status: string, from?: ?int, to?: ?int, error?: string, seconds?: int}
     */
    public function refreshAccount(int $accountId, AccountRefresh $refresh): array
    {
        return $refresh->execute(auth()->user(), $accountId);
    }

    /**
     * Equips the given character for the authenticated user, persists it,
     * and broadcasts the change — ported verbatim from the old
     * CharacterSelect::equip(), whose own docblock this repeats. Unknown
     * keys are silently rejected.
     *
     * @param  string  $key  a FighterCharacter enum value
     * @return void
     */
    public function equip(string $key): void
    {
        $character = FighterCharacter::tryFrom($key);

        if ($character === null) {
            return;
        }

        $user = auth()->user();

        if (! $user) {
            return;
        }

        $user->forceFill(['equipped_character' => $character])->save();
        $this->equipped = $character->value;
        $this->equippedKey = $character->value;

        FighterCharacterChanged::dispatch($user);
    }

    /**
     * All playable fighter character values, in enum-declaration order —
     * ported from the old CharacterSelect::characters().
     *
     * @return array<int, string>
     */
    public function characters(): array
    {
        return array_column(FighterCharacter::cases(), 'value');
    }

    /**
     * The playable roster with its attack type, for the Character tab's
     * filter chips — ported from characters() but pairing each value with
     * FighterCharacter::attackType() rather than changing that method's
     * existing plain-string-array return type (other callers may rely on it).
     *
     * @return array<int, array{key: string, attackType: string}>
     */
    public function charactersWithMeta(): array
    {
        return array_map(
            fn (FighterCharacter $c): array => ['key' => $c->value, 'attackType' => $c->attackType()],
            FighterCharacter::cases(),
        );
    }

    /**
     * Resolves the currently selected damage window into the from/to
     * DamageByPeriod actually reads over.
     *
     * @param  DamageByPeriod  $service
     * @return array{mine:int, team:int, share:float}
     */
    private function damage(DamageByPeriod $service): array
    {
        $user = auth()->user();

        if ($this->period === 'custom') {
            return $service->between($user, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
        }

        return $service->for($user, Period::tryFrom($this->period) ?? Period::Today);
    }

    /**
     * Rank, token ledger and delta for the same window damage() reads.
     *
     * @param  PeriodStats  $service
     * @return array{rank: ?int, tokens: array{out: int, in: int, cw: int, cr: int}, delta: ?float}
     */
    private function stats(PeriodStats $service): array
    {
        $user = auth()->user();

        if ($this->period === 'custom') {
            return $service->between($user, CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to));
        }

        return $service->for($user, Period::tryFrom($this->period) ?? Period::Today);
    }

    /**
     * Snapshot of how the user's latest event was attributed — ported from
     * the old Profile::attributionStatus().
     *
     * @param  User  $user
     * @param  CachedLatestVersion  $latest
     * @return array{event:?Event, clientVersion:?string, latestVersion:?string, outdated:bool, hookVersion:?string, latestHookVersion:string, hookOutdated:bool}
     */
    private function attributionStatus(User $user, CachedLatestVersion $latest): array
    {
        $latestVersion = $latest->get();

        return [
            'event' => Event::where('user_id', $user->id)->latest('id')->first(),
            'clientVersion' => $user->client_version,
            'latestVersion' => $latestVersion,
            'outdated' => $latestVersion !== null && $user->client_version !== null && version_compare($user->client_version, $latestVersion, '<'),
            'hookVersion' => $user->hook_version,
            'latestHookVersion' => config('token_slayer.hook_version'),
            'hookOutdated' => HookVersionStatus::needsManualNudge($user, config('token_slayer.hook_version')),
        ];
    }

    /**
     * Renders the sheet; the aggregates below only run while it is open.
     *
     * @param  DamageByPeriod  $damageService
     * @param  DamageByModel  $byModelService
     * @param  HourlyDamage  $hourlyService
     * @param  BossKillCount  $killsService
     * @param  AccountQuotaCards  $accountsService
     * @param  DamageTotals  $totalsService
     * @param  CachedLatestVersion  $latest
     * @param  AccountAlerts  $alertsService
     * @param  CharacterRoommates  $roommatesService
     * @param  SubagentCountCache  $minionCache
     * @param  PeriodStats  $statsService
     * @param  AccountCardSummaries  $cardSummaries
     * @return View
     */
    public function render(
        DamageByPeriod $damageService,
        DamageByModel $byModelService,
        HourlyDamage $hourlyService,
        BossKillCount $killsService,
        AccountQuotaCards $accountsService,
        DamageTotals $totalsService,
        CachedLatestVersion $latest,
        AccountAlerts $alertsService,
        CharacterRoommates $roommatesService,
        SubagentCountCache $minionCache,
        PeriodStats $statsService,
        AccountCardSummaries $cardSummaries,
    ): View {
        // Cheap and worth showing even while the sheet itself is closed (a
        // persistent hook/attribution chip near wherever it opens from) —
        // unlike the aggregates below, this is a single indexed lookup, not
        // a full-table SUM, so it stays outside the $open gate.
        $attribution = $this->attributionStatus(auth()->user(), $latest);

        if (! $this->open) {
            return view('livewire.fighter-sheet', ['open' => false, 'attribution' => $attribution]);
        }

        $user = auth()->user();
        $accounts = $accountsService->for($user);
        $damageTotals = $totalsService->forUser($user);

        return view('livewire.fighter-sheet', [
            'open' => true,
            'damage' => $this->damage($damageService),
            'stats' => $this->stats($statsService),
            // top four, the tail folded into "Other models" so Clawd stays in view
            'byModel' => TopRows::fold($byModelService->for($user, Period::tryFrom($this->modelPeriod) ?? Period::Today), 4),
            'hourly' => $hourlyService->last24($user),
            'kills' => $killsService->for($user),
            'accounts' => $cardSummaries->present($accounts),
            // Ported from the old Profile page (Task 42 parity): rolling-
            // window totals distinct from the period-tab damage above.
            'damageTotals' => $damageTotals,
            'globalUsage' => $totalsService->global(),
            // Nothing ever landed: the mockup's empty state replaces the stats.
            'newbie' => $damageTotals['allTime'] === 0 && $attribution['event'] === null,
            'attribution' => $attribution,
            'alerts' => $alertsService->for($accounts, $attribution),
            'roommates' => $roommatesService->for(),
            'minions' => $minionCache->countFor($user->id),
        ]);
    }
}
