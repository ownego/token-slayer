<?php

use App\Enums\MembershipStatus;
use App\Filament\Widgets\TotalActiveUsers;
use App\Models\Account;
use App\Models\CodexCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('counts distinct tracked users across claude and codex accounts, without double-counting', function (): void {
    $claudeAccount = Account::factory()->create();
    $codexAccount = Account::create(['email' => 'codex@example.com', 'provider' => 'codex']);
    CodexCredential::create(['account_id' => $codexAccount->id]);

    $onlyClaude = User::factory()->create();
    $onlyCodex = User::factory()->create();
    $both = User::factory()->create();
    $untracked = User::factory()->create();

    $claudeAccount->users()->attach([
        $onlyClaude->id => ['status' => MembershipStatus::Tracked->value],
        $both->id => ['status' => MembershipStatus::Tracked->value],
        $untracked->id => ['status' => MembershipStatus::Untracked->value],
    ]);
    $codexAccount->users()->attach([
        $onlyCodex->id => ['status' => MembershipStatus::Tracked->value],
        $both->id => ['status' => MembershipStatus::Tracked->value],
    ]);

    Livewire::test(TotalActiveUsers::class)
        ->assertSee('3');
});
