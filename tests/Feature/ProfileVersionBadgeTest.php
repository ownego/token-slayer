<?php

use App\Livewire\Profile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();   // the badge reads through CachedLatestVersion — never let it bleed between tests
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
});

test('hides the outdated badge when the latest version is unknown', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertOk()
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === null
                && $attribution['outdated'] === false,
        );
});

test('flags the client as outdated when its version is older', function () {
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1.0.0']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === '1.0.4'
                && $attribution['outdated'] === true,
        );
});

test('is not outdated when the client already runs the latest version', function () {
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.0.4',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1.0.4']);

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->assertViewHas(
            'attribution',
            fn ($attribution) => $attribution['latestVersion'] === '1.0.4'
                && $attribution['outdated'] === false,
        );
});
