<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use App\Services\Attribution\EventAttributionBackfiller;
use App\Services\DamageTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

test('backfill attributes only the given org\'s null-account events and returns the count', function () {
    $user = User::factory()->create();
    $a = Account::factory()->withOrganizationUuid('org-a')->create();
    $b = Account::factory()->withOrganizationUuid('org-b')->create();

    Event::factory()->count(2)->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-b', 'user_id' => $user->id]);
    $already = Event::factory()->create(['account_id' => $a->id, 'account_org_id' => 'org-a', 'user_id' => $user->id]);

    $result = app(EventAttributionBackfiller::class)->backfill('org-a');

    expect($result)->toBe(['org-a' => 2]);
    expect(Event::where('account_org_id', 'org-a')->whereNull('account_id')->count())->toBe(0);
    expect(Event::where('account_org_id', 'org-a')->where('account_id', $a->id)->count())->toBe(3);
    // org-b untouched.
    expect(Event::where('account_org_id', 'org-b')->whereNull('account_id')->count())->toBe(1);
});

test('backfill with no arg attributes every org that has a matching account', function () {
    $user = User::factory()->create();
    $a = Account::factory()->withOrganizationUuid('org-a')->create();
    Account::factory()->withOrganizationUuid('org-b')->create();

    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-b', 'user_id' => $user->id]);
    // org-c has events but NO account — must stay null.
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-c', 'user_id' => $user->id]);

    $result = app(EventAttributionBackfiller::class)->backfill();

    expect($result)->toEqual(['org-a' => 1, 'org-b' => 1]);
    expect(Event::where('account_org_id', 'org-c')->whereNull('account_id')->count())->toBe(1);
});

test('backfill forgets the damage-totals cache when it attributes rows, and is a no-op otherwise', function () {
    $user = User::factory()->create();
    $a = Account::factory()->withOrganizationUuid('org-a')->create();
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);

    Cache::put(DamageTotals::CACHE_KEY, ['sentinel'], 3600);
    app(EventAttributionBackfiller::class)->backfill('org-a');
    expect(Cache::has(DamageTotals::CACHE_KEY))->toBeFalse();

    // No matching account for org-z → attributes nothing, returns [].
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-z', 'user_id' => $user->id]);
    Cache::put(DamageTotals::CACHE_KEY, ['sentinel'], 3600);
    expect(app(EventAttributionBackfiller::class)->backfill('org-z'))->toBe([]);
    expect(Cache::has(DamageTotals::CACHE_KEY))->toBeTrue();
});
