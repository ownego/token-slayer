<?php

use App\Events\AccountTokenRejected;
use App\Models\Account;
use App\Services\AccountReauthAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('dispatching the event delegates to the alerter with the account and reason', function () {
    $account = Account::factory()->needsReauth()->create();

    $this->mock(AccountReauthAlerter::class, function (MockInterface $mock) use ($account) {
        $mock->shouldReceive('alert')
            ->once()
            ->withArgs(fn (Account $a, string $reason) => $a->is($account) && $reason === 'invalid_grant')
            ->andReturn(true);
    });

    AccountTokenRejected::dispatch($account, 'invalid_grant');
});
