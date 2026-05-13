<?php

use App\Events\BossKilled;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['game.slack_kill_webhook_url' => 'https://hooks.slack/test']);
    Http::fake();
});

test('boss kill posts Block Kit payload with killer and new boss', function () {
    $killer = User::factory()->create(['slack_handle' => 'alice']);
    $killed = Boss::factory()->defeated()->create([
        'number' => 12,
        'killing_blow_user_id' => $killer->id,
    ]);
    Boss::factory()->create(['number' => 13, 'max_hp' => 13_000_000]);

    event(new BossKilled($killed, $killer));

    Http::assertSent(function ($request) {
        expect($request->url())->toBe('https://hooks.slack/test');
        expect($request['text'])
            ->toContain('Boss #12')
            ->toContain('@alice')
            ->toContain('Boss #13');

        $blocks = $request['blocks'];
        expect($blocks[0]['type'])->toBe('header');
        expect($blocks[0]['text']['text'])->toContain('Boss #12');

        $summaryFields = collect($blocks[1]['fields'])->pluck('text')->implode("\n");
        expect($summaryFields)
            ->toContain('@alice')
            ->toContain('Boss #13')
            ->toContain('13,000,000');

        return true;
    });
});

test('listener is a no-op when webhook URL is not configured', function () {
    config(['game.slack_kill_webhook_url' => null]);

    $killer = User::factory()->create(['slack_handle' => 'bob']);
    $killed = Boss::factory()->defeated()->create([
        'number' => 5,
        'killing_blow_user_id' => $killer->id,
    ]);

    event(new BossKilled($killed, $killer));

    Http::assertNothingSent();
});

test('top damage section ranks dealers and shows up to three', function () {
    $alice = User::factory()->create(['slack_handle' => 'alice']);
    $bob = User::factory()->create(['slack_handle' => 'bob']);
    $carol = User::factory()->create(['slack_handle' => 'carol']);
    $dave = User::factory()->create(['slack_handle' => 'dave']);

    $killed = Boss::factory()->defeated()->create(['number' => 7, 'killing_blow_user_id' => $bob->id]);
    Boss::factory()->create(['number' => 8]);

    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $killed->id, 'tokens' => 100_000]);
    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $killed->id, 'tokens' => 350_000]);
    Event::factory()->create(['user_id' => $bob->id, 'boss_id' => $killed->id, 'tokens' => 320_000]);
    Event::factory()->create(['user_id' => $carol->id, 'boss_id' => $killed->id, 'tokens' => 230_000]);
    Event::factory()->create(['user_id' => $dave->id, 'boss_id' => $killed->id, 'tokens' => 90_000]);

    event(new BossKilled($killed, $bob));

    Http::assertSent(function ($request) {
        $topSection = collect($request['blocks'])->first(
            fn ($block) => ($block['type'] ?? null) === 'section'
                && str_contains($block['text']['text'] ?? '', 'Top damage')
        );
        expect($topSection)->not->toBeNull();

        $text = $topSection['text']['text'];
        $alicePos = strpos($text, '@alice');
        $bobPos = strpos($text, '@bob');
        $carolPos = strpos($text, '@carol');

        expect($alicePos)->toBeLessThan($bobPos);
        expect($bobPos)->toBeLessThan($carolPos);
        expect($text)->toContain('🥇')->toContain('🥈')->toContain('🥉');
        expect($text)->toContain('450,000');
        expect($text)->not->toContain('@dave');

        return true;
    });
});

test('omits new boss field when no alive boss exists', function () {
    $killer = User::factory()->create(['slack_handle' => 'alice']);
    $killed = Boss::factory()->defeated()->create(['number' => 9, 'killing_blow_user_id' => $killer->id]);

    event(new BossKilled($killed, $killer));

    Http::assertSent(function ($request) {
        $summary = collect($request['blocks'][1]['fields'])->pluck('text')->implode("\n");
        expect($summary)
            ->toContain('Killing blow')
            ->not->toContain('New boss');

        return true;
    });
});

test('renders fewer than three rows when fewer dealers contributed', function () {
    $alice = User::factory()->create(['slack_handle' => 'alice']);
    $bob = User::factory()->create(['slack_handle' => 'bob']);

    $killed = Boss::factory()->defeated()->create(['number' => 4, 'killing_blow_user_id' => $alice->id]);
    Boss::factory()->create(['number' => 5]);

    Event::factory()->create(['user_id' => $alice->id, 'boss_id' => $killed->id, 'tokens' => 500_000]);
    Event::factory()->create(['user_id' => $bob->id, 'boss_id' => $killed->id, 'tokens' => 200_000]);

    event(new BossKilled($killed, $alice));

    Http::assertSent(function ($request) {
        $topSection = collect($request['blocks'])->first(
            fn ($block) => ($block['type'] ?? null) === 'section'
                && str_contains($block['text']['text'] ?? '', 'Top damage')
        );
        $text = $topSection['text']['text'];

        expect($text)->toContain('🥇')->toContain('🥈')->not->toContain('🥉');

        return true;
    });
});

test('killer without slack_handle falls back to name', function () {
    $killer = User::factory()->create(['name' => 'Anonymous Coder', 'slack_handle' => null]);
    $killed = Boss::factory()->defeated()->create(['number' => 3, 'killing_blow_user_id' => $killer->id]);
    Boss::factory()->create(['number' => 4]);

    event(new BossKilled($killed, $killer));

    Http::assertSent(function ($request) {
        $summary = collect($request['blocks'][1]['fields'])->pluck('text')->implode("\n");
        expect($summary)
            ->toContain('Anonymous Coder')
            ->not->toContain('@Anonymous');

        return true;
    });
});
