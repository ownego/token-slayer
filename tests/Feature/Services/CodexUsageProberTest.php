<?php

use App\Models\Account;
use App\Models\CodexCredential;
use App\Services\Accounts\CodexUsageWindows;
use App\Services\CodexUsageProber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('records a usage snapshot from the real captured response shape (free-tier, single window)', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create([
        'chatgpt_account_id' => 'acct-fixture-1',
        'codex_access_token' => 'fake-access-token',
    ]);

    $fixture = json_decode(file_get_contents(base_path('tests/fixtures/codex/usage.json')), true);
    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response($fixture, 200)]);

    $snapshot = app(CodexUsageProber::class)->probe($account->fresh());

    expect($snapshot)->not->toBeNull()
        // A free-tier account's single window is a 30-day monthly cap, not
        // the 5h/weekly split. It used to be filed into the 5-hour column
        // anyway, which put a month's usage behind an hour's name. Neither
        // typed column is a truthful home for it, so both stay null and the
        // window is read back from `raw` with its real duration — see
        // {@see CodexUsageWindows}.
        ->and($snapshot->util_5h)->toBeNull()
        ->and($snapshot->util_7d)->toBeNull()
        // No reset either: the deadline belonged to the window that is no
        // longer being filed here.
        ->and($snapshot->reset_5h_at)->toBeNull()
        // The window itself is not lost — `raw` keeps it, labelled by its own
        // duration rather than by a column it does not fit.
        ->and(CodexUsageWindows::from($snapshot->raw)[0]['label'])->toBe('30D')
        ->and($account->fresh()->last_probed_at)->not->toBeNull()
        ->and($account->fresh()->probe_error)->toBeNull();

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer fake-access-token')
        && $request->hasHeader('OpenAI-Beta', 'codex-1')
        && $request->hasHeader('originator', 'Codex Desktop')
        && $request->hasHeader('ChatGPT-Account-Id', 'acct-fixture-1'));
});

it('classifies a two-window (paid-tier) response into 5h and 7d by window duration', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create(['codex_access_token' => 'fake-access-token']);

    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response([
        'plan_type' => 'plus',
        'rate_limit' => [
            'allowed' => true,
            'limit_reached' => false,
            'primary_window' => ['used_percent' => 42, 'limit_window_seconds' => 18000, 'reset_after_seconds' => 3600, 'reset_at' => now()->addHour()->timestamp],
            'secondary_window' => ['used_percent' => 17, 'limit_window_seconds' => 604800, 'reset_after_seconds' => 86400, 'reset_at' => now()->addDay()->timestamp],
        ],
    ], 200)]);

    $snapshot = app(CodexUsageProber::class)->probe($account->fresh());

    expect($snapshot->util_5h)->toBe(42)
        ->and($snapshot->util_7d)->toBe(17);
});

it('returns null without recording an error on a 429', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create(['codex_access_token' => 'fake-access-token']);

    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response([], 429)]);

    $snapshot = app(CodexUsageProber::class)->probe($account->fresh());

    expect($snapshot)->toBeNull()
        ->and($account->fresh()->probe_error)->toBeNull();
});

it('records a safe probe_error on a non-rate-limit failure, without leaking the access token', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create(['codex_access_token' => 'fake-access-token']);

    Http::fake(['chatgpt.com/backend-api/wham/usage' => Http::response([], 500)]);

    $snapshot = app(CodexUsageProber::class)->probe($account->fresh());

    expect($snapshot)->toBeNull();
    $account->refresh();
    expect($account->probe_error)->not->toBeNull()
        ->and($account->probe_error)->not->toContain('fake-access-token');
});

it('returns null without an HTTP call when the account has no codex_access_token', function (): void {
    $account = Account::factory()->create(['provider' => 'codex']);
    CodexCredential::factory()->for($account)->create(['codex_access_token' => null]);

    $snapshot = app(CodexUsageProber::class)->probe($account->fresh());

    expect($snapshot)->toBeNull();
    Http::assertNothingSent();
});
