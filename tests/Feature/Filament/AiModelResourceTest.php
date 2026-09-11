<?php

use App\Filament\Resources\AiModels\Pages\ListAiModels;
use App\Models\AiModel;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('a non-admin cannot open the page', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(ListAiModels::class)->assertForbidden();
});

test('lists every registered model with its total tokens and events', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-fable-5-1', 'tokens' => 600]);
    Event::factory()->for($admin)->create(['model' => 'claude-fable-5-1', 'tokens' => 28]);
    $row = AiModel::create(['model' => 'claude-fable-5-1']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertCanSeeTableRecords([$row])
        ->assertTableColumnStateSet('tokens', '628', $row);
});

test('toggling the badge column persists flair_enabled', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => false]);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->call('updateTableColumnState', 'flair_enabled', (string) $row->getKey(), true);

    expect($row->fresh()->flair_enabled)->toBeTrue();
});

test('a role with only ViewAny:AiModel does not even see the badge column', function () {
    // ToggleColumn saves directly without consulting the resource's Policy
    // (Filament's own doc-comment on the column says as much) -- the Sync
    // and Edit-animation actions are `.authorize('update')`d, but the badge
    // toggle had no `->visible()` guard, so a viewer-only role both saw it
    // rendered AND could flip it through the same `updateTableColumnState`
    // call the admin test above uses, despite holding no Update:AiModel
    // permission. Hidden entirely, not just disabled: a read-only viewer has
    // no business seeing a control they can never act on.
    $role = Role::create(['name' => 'model_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:AiModel');
    $user = User::factory()->create();
    $user->assignRole('model_viewer');
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => false]);

    Livewire::actingAs($user)->test(ListAiModels::class)
        ->assertTableColumnHidden('flair_enabled')
        ->call('updateTableColumnState', 'flair_enabled', (string) $row->getKey(), true);

    expect($row->fresh()->flair_enabled)->toBeFalse();
});

test('a role with Update:AiModel sees and can still toggle the badge column', function () {
    $role = Role::create(['name' => 'model_editor', 'guard_name' => 'web']);
    $role->givePermissionTo(['ViewAny:AiModel', 'Update:AiModel']);
    $user = User::factory()->create();
    $user->assignRole('model_editor');
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_enabled' => false]);

    Livewire::actingAs($user)->test(ListAiModels::class)
        ->assertTableColumnVisible('flair_enabled')
        ->call('updateTableColumnState', 'flair_enabled', (string) $row->getKey(), true);

    expect($row->fresh()->flair_enabled)->toBeTrue();
});

test('the list defaults to the biggest token spender first, not alphabetical order', function () {
    $admin = User::factory()->admin()->create();
    $lastAlphabetically = AiModel::create(['model' => 'zzz-model']);
    $firstAlphabetically = AiModel::create(['model' => 'aaa-model']);
    Event::factory()->for($admin)->create(['model' => 'zzz-model', 'tokens' => 900_000]);
    Event::factory()->for($admin)->create(['model' => 'aaa-model', 'tokens' => 100]);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertCanSeeTableRecords([$lastAlphabetically, $firstAlphabetically], inOrder: true);
});

test('duration and color are not table columns -- only the animation popup edits them', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->assertTableColumnDoesNotExist('flair_duration_ms')
        ->assertTableColumnDoesNotExist('flair_color');
});

test('the animation popup prefills the row current duration and color', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->mountTableAction('edit-animation', $row)
        ->assertTableActionDataSet(['flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);
});

test('saving the animation popup persists the new duration and color', function () {
    $admin = User::factory()->admin()->create();
    $row = AiModel::create(['model' => 'claude-opus-5', 'flair_duration_ms' => 6000, 'flair_color' => '#a855f7']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('edit-animation', $row, ['flair_duration_ms' => 9000, 'flair_color' => '#22d3ee']);

    $row->refresh();
    expect($row->flair_duration_ms)->toBe(9000)
        ->and($row->flair_color)->toBe('#22d3ee');
});

test('the preview x-data attribute is not truncated by a stray double quote', function () {
    // The whole Alpine component lives inside a double-quoted x-data
    // attribute, so ONE literal `"` anywhere in it -- including in a code
    // comment -- ends the attribute early, and the browser renders the rest
    // of the component as visible page text. This has now happened twice
    // (an escaped \" in a canvas font string, then a quoted word in a
    // comment), and neither broke any other test: the Blade still compiles,
    // the view still renders, it just silently ships a broken modal.
    $html = view('filament.flair-preview', [
        'color' => '#fbbf24',
        'durationMs' => 6000,
        'label' => 'FABLE',
    ])->render();

    $start = strpos($html, 'x-data="') + 8;
    $attribute = substr($html, $start, strpos($html, '"', $start) - $start);

    // The last statement of the component's own frame loop -- present only
    // if the attribute survived intact all the way to the end.
    expect($attribute)->toContain('requestAnimationFrame(next => this.frame(next))');
});

test('the sync action discovers new models and reports how many', function () {
    $admin = User::factory()->admin()->create();
    Event::factory()->for($admin)->create(['model' => 'claude-sonnet-5']);

    Livewire::actingAs($admin)->test(ListAiModels::class)
        ->callTableAction('sync')
        ->assertNotified();

    expect(AiModel::where('model', 'claude-sonnet-5')->exists())->toBeTrue();
});
