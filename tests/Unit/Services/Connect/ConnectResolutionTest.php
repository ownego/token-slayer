<?php

use App\Enums\AccountPlan;
use App\Models\Account;
use App\Services\Connect\ConnectDraft;
use App\Services\Connect\ConnectResolution;

test('existing wraps an account and reports isExisting true', function () {
    $account = new Account(['email' => 'a@example.com']);

    $resolution = ConnectResolution::existing($account);

    expect($resolution->isExisting())->toBeTrue()
        ->and($resolution->account)->toBe($account)
        ->and($resolution->draft)->toBeNull();
});

test('pending wraps a draft and reports isExisting false', function () {
    $draft = new ConnectDraft(
        email: 'a@example.com',
        orgUuid: 'org-uuid',
        plan: AccountPlan::Max20x,
        organizationType: 'claude_max',
        rateLimitTier: 'default_claude_max_20x',
        name: 'Acme',
        handoffKey: 'handoff-key',
    );

    $resolution = ConnectResolution::pending($draft);

    expect($resolution->isExisting())->toBeFalse()
        ->and($resolution->draft)->toBe($draft)
        ->and($resolution->account)->toBeNull();
});

test('draft exposes its promoted fields', function () {
    $draft = new ConnectDraft(
        email: 'a@example.com',
        orgUuid: 'org-uuid',
        plan: AccountPlan::Max5x,
        organizationType: 'claude_max',
        rateLimitTier: 'default_claude_max_5x',
        name: 'Acme',
        handoffKey: 'handoff-key',
    );

    expect($draft->email)->toBe('a@example.com')
        ->and($draft->orgUuid)->toBe('org-uuid')
        ->and($draft->plan)->toBe(AccountPlan::Max5x)
        ->and($draft->organizationType)->toBe('claude_max')
        ->and($draft->rateLimitTier)->toBe('default_claude_max_5x')
        ->and($draft->name)->toBe('Acme')
        ->and($draft->handoffKey)->toBe('handoff-key');
});
