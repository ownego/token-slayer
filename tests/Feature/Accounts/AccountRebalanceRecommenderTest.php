<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\AccountUsageSnapshot;
use App\Models\Event;
use App\Models\User;
use App\Services\Accounts\AccountRebalanceRecommender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('recommends moving the heaviest contributor off an overflowing account onto one with headroom', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');

    $overflowing = Account::factory()->connected()->create();
    $healthy = Account::factory()->connected()->create();
    $resetAt = now()->addDays(4);

    // overflowing: single event, so peak-day tokens equal the trailing-7d
    // total. tokensPerPercent = 84,000 / 30 = 2,800; rate = 84,000 / 2,800
    // = 30 (= util_7d, the single-event identity). projected = 30 + 30*4 =
    // 150. overflow = (150 - 100) * 2,800 = 140,000.
    AccountUsageSnapshot::factory()->for($overflowing)->create(['util_7d' => 30, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $heavy = User::factory()->create();
    Event::factory()->for($overflowing)->for($heavy)->create(['tokens' => 84_000, 'created_at' => now()->subDay()]);
    $overflowing->users()->syncWithoutDetaching([$heavy->id => ['status' => MembershipStatus::Tracked->value]]);

    // healthy: tokensPerPercent = 10,000 / 5 = 2,000; rate = 10,000 / 2,000
    // = 5 (= util_7d). projected = 5 + 5*4 = 25. headroom = (100 - 25) *
    // 2,000 = 150,000 -- comfortably above heavy's ~84,000 weekly demand.
    AccountUsageSnapshot::factory()->for($healthy)->create(['util_7d' => 5, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($healthy)->for(User::factory())->create(['tokens' => 10_000, 'created_at' => now()->subDays(3)]);

    $result = app(AccountRebalanceRecommender::class)->recommend();

    expect($result['moves'])->toHaveCount(1);
    $move = $result['moves'][0];
    expect($move->userId)->toBe($heavy->id)
        ->and($move->fromAccountId)->toBe($overflowing->id)
        ->and($move->toAccountId)->toBe($healthy->id);

    Carbon::setTestNow();
});

it('accumulates the target account\'s projected util across multiple moves onto it, not the stale original', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');
    $resetAt = now()->addDays(4);

    // target: single event, so tokensPerPercent = 100,000 / 5 = 20,000;
    // rate = 5 (= util_7d). projected = 5 + 5*4 = 25. headroom =
    // (100 - 25) * 20,000 = 1,500,000 -- big enough to absorb both moves
    // below without running out itself.
    $target = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($target)->create(['util_7d' => 5, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($target)->for(User::factory())->create(['tokens' => 100_000, 'created_at' => now()->subDay()]);

    // accountA: tokensPerPercent = 40,000 / 25 = 1,600; rate = 25.
    // projected = 25 + 25*4 = 125. overflow = (125 - 100) * 1,600 = 40,000.
    $accountA = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($accountA)->create(['util_7d' => 25, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $userA = User::factory()->create();
    Event::factory()->for($accountA)->for($userA)->create(['tokens' => 40_000, 'created_at' => now()->subDay()]);
    $accountA->users()->syncWithoutDetaching([$userA->id => ['status' => MembershipStatus::Tracked->value]]);

    // accountB: tokensPerPercent = 22,000 / 22 = 1,000; rate = 22.
    // projected = 22 + 22*4 = 110. overflow = (110 - 100) * 1,000 = 10,000.
    // Smaller overflow than accountA, so accountA is processed first.
    $accountB = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($accountB)->create(['util_7d' => 22, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $userB = User::factory()->create();
    Event::factory()->for($accountB)->for($userB)->create(['tokens' => 22_000, 'created_at' => now()->subDay()]);
    $accountB->users()->syncWithoutDetaching([$userB->id => ['status' => MembershipStatus::Tracked->value]]);

    $result = app(AccountRebalanceRecommender::class)->recommend();

    expect($result['moves'])->toHaveCount(2);
    [$moveA, $moveB] = $result['moves'];

    // accountA's move: target starts at its true baseline, 25%.
    expect($moveA->fromAccountId)->toBe($accountA->id)
        ->and($moveA->toAccountId)->toBe($target->id)
        ->and($moveA->toProjectedBefore)->toBe(25)
        // +round(40,000 / 20,000) = +2
        ->and($moveA->toProjectedAfter)->toBe(27);

    // accountB's move onto the SAME target: before must reflect accountA's
    // move having already landed (27), not the stale original (25).
    expect($moveB->fromAccountId)->toBe($accountB->id)
        ->and($moveB->toAccountId)->toBe($target->id)
        ->and($moveB->toProjectedBefore)->toBe(27)
        // +round(22,000 / 20,000) = +1
        ->and($moveB->toProjectedAfter)->toBe(28);

    Carbon::setTestNow();
});

it('accumulates fractional sub-1% contributions across moves instead of rounding each one away to zero first', function () {
    // A real-world case this exact shape reproduces: a target account so
    // large that any single move's percent-equivalent rounds to 0% on its
    // own. Rounding that 0 and *then* adding it, move after move, would
    // permanently pin the displayed projection at its original value no
    // matter how many moves land on it. Accumulating the raw (unrounded)
    // percent internally, and rounding only for display, lets several
    // sub-1% moves add up and eventually cross a whole percentage point.
    Carbon::setTestNow('2026-09-12 00:00:00');
    $resetAt = now()->addDays(4);

    // target: tokensPerPercent = 1,000,000 / 1 = 1,000,000; rate = 1.
    // projected = 1 + 1*4 = 5.
    $target = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($target)->create(['util_7d' => 1, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    Event::factory()->for($target)->for(User::factory())->create(['tokens' => 1_000_000, 'created_at' => now()->subDay()]);

    // accountA: tokensPerPercent = 400,000 / 30 ≈ 13,333; rate = 30.
    // projected = 30 + 30*4 = 150. Weekly demand = 400,000 tokens ->
    // percent-equivalent onto target = 400,000 / 1,000,000 = 0.4 (rounds
    // to 0 alone).
    $accountA = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($accountA)->create(['util_7d' => 30, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $userA = User::factory()->create();
    Event::factory()->for($accountA)->for($userA)->create(['tokens' => 400_000, 'created_at' => now()->subDay()]);
    $accountA->users()->syncWithoutDetaching([$userA->id => ['status' => MembershipStatus::Tracked->value]]);

    // accountB: tokensPerPercent = 400,000 / 28 ≈ 14,286; rate = 28.
    // projected = 28 + 28*4 = 140. Slightly less overflow than accountA
    // (571,428 vs 666,666), so accountA is processed first. Same 400,000
    // weekly demand -> another 0.4 onto the target.
    $accountB = Account::factory()->connected()->create();
    AccountUsageSnapshot::factory()->for($accountB)->create(['util_7d' => 28, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    $userB = User::factory()->create();
    Event::factory()->for($accountB)->for($userB)->create(['tokens' => 400_000, 'created_at' => now()->subDay()]);
    $accountB->users()->syncWithoutDetaching([$userB->id => ['status' => MembershipStatus::Tracked->value]]);

    $result = app(AccountRebalanceRecommender::class)->recommend();

    expect($result['moves'])->toHaveCount(2);
    [$moveA, $moveB] = $result['moves'];

    // Move 1 alone: 5 + 0.4 = 5.4, still displays as 5 -- correct, a single
    // sub-1% move should not visibly move the needle by itself.
    expect($moveA->toProjectedBefore)->toBe(5)
        ->and($moveA->toProjectedAfter)->toBe(5);

    // Move 2: the RAW accumulator is now 5.4 (not the stale 5), so adding
    // its own 0.4 reaches 5.8, which rounds up to 6 -- proof the two 0.4s
    // were summed before rounding, not rounded-then-summed.
    expect($moveB->toProjectedBefore)->toBe(5)
        ->and($moveB->toProjectedAfter)->toBe(6);

    Carbon::setTestNow();
});

it('reports unresolved overflow when no account has enough headroom to absorb it', function () {
    Carbon::setTestNow('2026-09-12 00:00:00');

    $accountA = Account::factory()->connected()->create();
    $accountB = Account::factory()->connected()->create();
    $resetAt = now()->addDays(4);

    // Both accounts already deeply overflowing (single-event identity:
    // projected = 90 + 90*4 = 450, well past 100) -- no target can help
    // either.
    AccountUsageSnapshot::factory()->for($accountA)->create(['util_7d' => 90, 'reset_7d_at' => $resetAt, 'created_at' => now()]);
    AccountUsageSnapshot::factory()->for($accountB)->create(['util_7d' => 90, 'reset_7d_at' => $resetAt, 'created_at' => now()]);

    $userA = User::factory()->create();
    $userB = User::factory()->create();
    Event::factory()->for($accountA)->for($userA)->create(['tokens' => 90_000, 'created_at' => now()->subDay()]);
    Event::factory()->for($accountB)->for($userB)->create(['tokens' => 90_000, 'created_at' => now()->subDay()]);
    $accountA->users()->syncWithoutDetaching([$userA->id => ['status' => MembershipStatus::Tracked->value]]);
    $accountB->users()->syncWithoutDetaching([$userB->id => ['status' => MembershipStatus::Tracked->value]]);

    $result = app(AccountRebalanceRecommender::class)->recommend();

    expect($result['moves'])->toBe([])
        ->and($result['unresolved_overflow_tokens'])->toBeGreaterThan(0.0)
        ->and($result['total_headroom_tokens'])->toBe(0.0);

    Carbon::setTestNow();
});
