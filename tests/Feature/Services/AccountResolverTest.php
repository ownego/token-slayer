<?php

use App\Models\Account;
use App\Services\AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('learns the organization uuid from an email-matched claim', function (): void {
    $account = Account::factory()->create(['email' => 'a@x.com']);

    app(AccountResolver::class)->resolve('org-new', 'a@x.com');

    expect($account->fresh()->organization_uuid)->toBe('org-new');
});

it('never overwrites a differing organization uuid and logs the conflict', function (): void {
    Log::shouldReceive('warning')->once();
    $account = Account::factory()->withOrganizationUuid('org-a')->create(['email' => 'a@x.com']);

    expect(app(AccountResolver::class)->resolve('org-b', 'a@x.com'))->toBe($account->id);

    expect($account->fresh()->organization_uuid)->toBe('org-a');
});

it('resolves a known org account email to its id', function () {
    $account = Account::factory()->create(['email' => 'Team@Ownego.com']);

    expect(app(AccountResolver::class)->resolve(null, 'team@ownego.com'))->toBe($account->id);
});

it('returns null for unknown or missing emails', function () {
    expect(app(AccountResolver::class)->resolve(null, 'stranger@gmail.com'))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(null, null))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(null, ''))->toBeNull();
});

it('picks up newly created accounts (cache invalidation)', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve(null, 'late@ownego.com'))->toBeNull();

    $account = Account::factory()->create(['email' => 'late@ownego.com']);

    expect($resolver->resolve(null, 'late@ownego.com'))->toBe($account->id);
});

it('resolves by organization uuid before email', function (): void {
    $byOrg = Account::factory()->withOrganizationUuid('org-a')->create(['email' => 'a@x.com']);
    $byEmail = Account::factory()->create(['email' => 'b@x.com']);

    expect(app(AccountResolver::class)->resolve('org-a', 'b@x.com'))->toBe($byOrg->id);
});

it('falls back to email when the org uuid is unknown', function (): void {
    $account = Account::factory()->create(['email' => 'b@x.com']);

    expect(app(AccountResolver::class)->resolve('org-zzz', 'B@X.com'))->toBe($account->id);
});

it('invalidates the org map when an account is saved', function (): void {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('org-late', null))->toBeNull();

    Account::factory()->withOrganizationUuid('org-late')->create();

    expect($resolver->resolve('org-late', null))->not->toBeNull();
});
