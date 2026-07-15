<?php

use App\Enums\AccountProfileSyncResult;
use App\Models\Account;
use App\Services\AccountProfileSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('sync applies plan and identity fields from a matching profile', function () {
    fakeAnthropic();

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
        'plan' => 'max-20x',
        'account_uuid' => null,
        'organization_uuid' => null,
    ]);

    $result = app(AccountProfileSyncer::class)->sync($account);

    $account->refresh();

    expect($result)->toBe(AccountProfileSyncResult::Synced)
        ->and($account->plan)->toBe('claude_pro')
        ->and($account->account_uuid)->toBe('adfeaf9f-dd9c-4c03-93c2-0bb05c7278b9')
        ->and($account->organization_uuid)->toBe('7f993a12-f480-45cd-8b99-1e3182d168bf')
        ->and($account->probe_error)->toBeNull();
});

test('sync flags an email mismatch and leaves plan and uuids unchanged', function () {
    fakeAnthropic();

    $account = Account::factory()->connected()->create([
        'email' => 'someone-else@example.com',
        'plan' => 'max-20x',
        'account_uuid' => 'original-uuid',
        'organization_uuid' => 'original-org-uuid',
    ]);

    $result = app(AccountProfileSyncer::class)->sync($account);

    $account->refresh();

    expect($result)->toBe(AccountProfileSyncResult::Mismatched)
        ->and($account->plan)->toBe('max-20x')
        ->and($account->account_uuid)->toBe('original-uuid')
        ->and($account->organization_uuid)->toBe('original-org-uuid')
        ->and($account->probe_error)->toContain('email mismatch');
});

test('sync records a safe probe_error when the profile call fails', function () {
    fakeAnthropic(['profile' => Http::response('', 401)]);

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
    ]);

    $result = app(AccountProfileSyncer::class)->sync($account);

    $account->refresh();

    expect($result)->toBe(AccountProfileSyncResult::Errored)
        ->and($account->probe_error)->toContain('profile sync failed')
        ->and($account->probe_error)->not->toContain('sk-ant');
});

test('sync handles an organization_uuid already claimed by another account', function () {
    fakeAnthropic();

    Account::factory()->create([
        'email' => 'holder@example.com',
        'organization_uuid' => '7f993a12-f480-45cd-8b99-1e3182d168bf',
    ]);

    $account = Account::factory()->connected()->create([
        'email' => 'ongtung2212002@gmail.com',
        'organization_uuid' => null,
    ]);

    $result = app(AccountProfileSyncer::class)->sync($account);

    $account->refresh();

    expect($result)->toBe(AccountProfileSyncResult::Synced)
        ->and($account->organization_uuid)->toBeNull()
        ->and($account->probe_error)->toBe('org uuid already claimed');
});
