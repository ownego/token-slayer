<?php

use App\Filament\Pages\UnrecognizedAccounts;
use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
});

test('the page loads for an admin and lists unrecognized org rows', function () {
    $user = User::factory()->create();
    Event::factory()->create(['account_id' => null, 'account_org_id' => 'org-visible', 'user_id' => $user->id]);

    Livewire::actingAs($this->admin)->test(UnrecognizedAccounts::class)
        ->assertOk()
        ->assertSee('org-visible');
});

test('mounting the backfill action for a matched org attributes its events', function () {
    $user = User::factory()->create();
    $account = Account::factory()->withOrganizationUuid('org-a')->create();
    Event::factory()->count(2)->create(['account_id' => null, 'account_org_id' => 'org-a', 'user_id' => $user->id]);

    Livewire::actingAs($this->admin)->test(UnrecognizedAccounts::class)
        ->mountAction('backfill', ['org' => 'org-a'])
        ->callMountedAction();

    expect(Event::whereNull('account_id')->where('account_org_id', 'org-a')->count())->toBe(0)
        ->and(Event::where('account_org_id', 'org-a')->where('account_id', $account->id)->count())->toBe(2);
});
