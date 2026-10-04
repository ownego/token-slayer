<?php

use App\Models\Account;
use App\Models\AccountReserveToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('lists only unused, undiscarded, unexpired tokens as available, soonest-expiring first', function (): void {
    $account = Account::factory()->create();
    $later = AccountReserveToken::factory()->for($account)->create(['session_expires_at' => now()->addDays(20)]);
    $sooner = AccountReserveToken::factory()->for($account)->create(['session_expires_at' => now()->addDays(3)]);
    AccountReserveToken::factory()->for($account)->used()->create();
    AccountReserveToken::factory()->for($account)->expired()->create();
    AccountReserveToken::factory()->for($account)->discarded()->create();

    expect($account->reserveTokens()->available()->pluck('id')->all())->toBe([$sooner->id, $later->id]);
});

it('stores the raw tokens encrypted at rest', function (): void {
    $token = AccountReserveToken::factory()->create(['refresh_token' => 'sk-ant-ort01-plain']);

    $raw = DB::table('account_reserve_tokens')->where('id', $token->id)->value('refresh_token');

    expect($raw)->not->toBe('sk-ant-ort01-plain')
        ->and($token->fresh()->refresh_token)->toBe('sk-ant-ort01-plain');
});

it('names its status for the Reserve tab', function (string $state, string $expected): void {
    $token = $state === 'available'
        ? AccountReserveToken::factory()->create()
        : AccountReserveToken::factory()->{$state}()->create();

    expect($token->statusLabel())->toBe($expected);
})->with([
    'available' => ['available', 'Available'],
    'used' => ['used', 'Used'],
    'expired' => ['expired', 'Expired'],
    'discarded' => ['discarded', 'Discarded'],
]);
