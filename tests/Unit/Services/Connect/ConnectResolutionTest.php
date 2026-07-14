<?php

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
    $draft = new ConnectDraft('a@example.com', 'org-uuid', 'max-20x', 'Acme', 'handoff-key');

    $resolution = ConnectResolution::pending($draft);

    expect($resolution->isExisting())->toBeFalse()
        ->and($resolution->draft)->toBe($draft)
        ->and($resolution->account)->toBeNull();
});

test('draft exposes its promoted fields', function () {
    $draft = new ConnectDraft('a@example.com', 'org-uuid', 'max-5x', 'Acme', 'handoff-key');

    expect($draft->email)->toBe('a@example.com')
        ->and($draft->orgUuid)->toBe('org-uuid')
        ->and($draft->plan)->toBe('max-5x')
        ->and($draft->name)->toBe('Acme')
        ->and($draft->handoffKey)->toBe('handoff-key');
});
