<?php

use App\Models\Event;
use App\Models\User;
use App\Services\Analytics\ActivityHeatmapQuery;
use App\Services\Analytics\UsageFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('it returns a dense 24x7 grid with token sums in the right cell', function () {
    $user = User::factory()->create();
    // A fixed instant: 2026-07-13 is a Monday → %w = 1, hour 09.
    $monday9am = Carbon::parse('2026-07-13 09:00:00');
    Event::factory()->for($user)->create(['tokens' => 250, 'created_at' => $monday9am]);
    Event::factory()->for($user)->create(['tokens' => 50, 'created_at' => $monday9am->copy()->addMinutes(20)]);

    $grid = app(ActivityHeatmapQuery::class)->get(
        new UsageFilters(Carbon::parse('2026-07-06'), Carbon::parse('2026-07-20'), null, null, null)
    );

    expect($grid)->toHaveCount(24 * 7);

    $cell = collect($grid)->first(fn ($c) => $c['weekday'] === 1 && $c['hour'] === 9);
    $empty = collect($grid)->first(fn ($c) => $c['weekday'] === 3 && $c['hour'] === 2);

    expect($cell['tokens'])->toBe(300)
        ->and($empty['tokens'])->toBe(0);
});
