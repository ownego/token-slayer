<?php

use App\Enums\MembershipStatus;
use App\Filament\Pages\AccountRebalance;
use App\Models\Account;
use App\Models\AccountUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('issues a grant on the target account and demotes the source membership, never detaching it', function () {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $source = Account::factory()->create();
    // Profile fixture authorizes ongtung2212002@gmail.com -- the target
    // account's identity must match or provisionForDevice() rejects the exchange.
    $target = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);
    $user->accounts()->syncWithoutDetaching([$source->id => ['status' => MembershipStatus::Tracked->value]]);

    Livewire::actingAs($admin)
        ->test(AccountRebalance::class)
        ->mountAction('switchUser', arguments: [
            'userId' => $user->id,
            'fromAccountId' => $source->id,
            'toAccountId' => $target->id,
        ])
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified();

    $sourcePivot = AccountUser::query()->where('user_id', $user->id)->where('account_id', $source->id)->firstOrFail();
    expect($sourcePivot->status)->toBe(MembershipStatus::Untracked);

    $targetPivot = AccountUser::query()->where('user_id', $user->id)->where('account_id', $target->id)->first();
    expect($targetPivot)->not->toBeNull();
});
