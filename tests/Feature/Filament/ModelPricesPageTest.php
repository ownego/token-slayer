<?php

use App\Filament\Pages\ModelPrices;
use App\Models\MissingModel;
use App\Models\ModelPrice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('creates its own page permission', function () {
    expect(Permission::where('name', 'View:ModelPrices')->exists())->toBeTrue();
});

it('is forbidden for a user without the permission', function () {
    Livewire::actingAs(User::factory()->create())->test(ModelPrices::class)->assertForbidden();
});

it('lists each model at its latest price and the models still missing one', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('View:ModelPrices');
    ModelPrice::factory()->create(['effective_from' => '2026-09-01', 'cache_read_per_mtok' => 0.20]);
    ModelPrice::factory()->create(['effective_from' => '2026-10-07', 'cache_read_per_mtok' => 0.10]);
    ModelPrice::factory()->codex()->create(['effective_from' => '2026-10-01']);
    MissingModel::factory()->create(['model' => 'gpt-unknown']);

    Livewire::actingAs($viewer)->test(ModelPrices::class)
        ->assertOk()
        ->assertSeeInOrder(['claude-sonnet-5-5', '2026-10-07', '$0.1', 'gpt-6-sol'])
        ->assertDontSee('2026-09-01')
        ->assertSee('gpt-unknown');
});

it('offers no way to change a price', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('View:ModelPrices');

    $page = Livewire::actingAs($viewer)->test(ModelPrices::class)->instance();

    expect($page->getCachedHeaderActions())->toBe([]);
});
