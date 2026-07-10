<?php

use App\Models\Account;
use App\Services\AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves a known org account email to its id', function () {
    $account = Account::factory()->create(['email' => 'Team@Ownego.com']);

    expect(app(AccountResolver::class)->resolve('team@ownego.com'))->toBe($account->id);
});

it('returns null for unknown or missing emails', function () {
    expect(app(AccountResolver::class)->resolve('stranger@gmail.com'))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(null))->toBeNull()
        ->and(app(AccountResolver::class)->resolve(''))->toBeNull();
});

it('picks up newly created accounts (cache invalidation)', function () {
    $resolver = app(AccountResolver::class);
    expect($resolver->resolve('late@ownego.com'))->toBeNull();

    $account = Account::factory()->create(['email' => 'late@ownego.com']);

    expect($resolver->resolve('late@ownego.com'))->toBe($account->id);
});
