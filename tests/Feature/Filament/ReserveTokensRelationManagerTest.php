<?php

use App\Filament\Resources\Accounts\Pages\EditAccount;
use App\Filament\Resources\Accounts\RelationManagers\ReserveTokensRelationManager;
use App\Models\Account;
use App\Models\AccountReserveToken;
use App\Models\CodexCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('lists available tokens with days left and hides used ones by default', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $available = AccountReserveToken::factory()->for($account)->create(['session_expires_at' => now()->addDays(12)->addHour()]);
    $used = AccountReserveToken::factory()->for($account)->used()->create();

    Livewire::actingAs($admin)
        ->test(ReserveTokensRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->assertCanSeeTableRecords([$available])
        ->assertCanNotSeeTableRecords([$used])
        ->assertSee('12d left');
});

it('badges the tab with the available count', function (): void {
    $account = Account::factory()->create();
    AccountReserveToken::factory()->for($account)->count(2)->create();
    AccountReserveToken::factory()->for($account)->expired()->create();

    expect(ReserveTokensRelationManager::getBadge($account, EditAccount::class))->toBe('2');
});

it('mints a reserve token from a pasted code', function (): void {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'ongtung2212002@gmail.com']);

    Livewire::actingAs($admin)
        ->test(ReserveTokensRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addReserveToken')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified('Reserve token added');

    expect($account->reserveTokens()->available()->count())->toBe(1)
        ->and($account->reserveTokens()->first()->created_by)->toBe($admin->id);
});

it('explains a code authorized for a different account instead of storing it', function (): void {
    fakeAnthropic();
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create(['email' => 'someone-else@example.com']);

    Livewire::actingAs($admin)
        ->test(ReserveTokensRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->mountAction('addReserveToken')
        ->setActionData(['code' => 'pasted-code'])
        ->callMountedAction()
        ->assertNotified('Adding the reserve token failed');

    expect($account->reserveTokens()->count())->toBe(0);
});

it('discards an available token', function (): void {
    $admin = User::factory()->admin()->create();
    $account = Account::factory()->create();
    $token = AccountReserveToken::factory()->for($account)->create();

    Livewire::actingAs($admin)
        ->test(ReserveTokensRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
        ->callTableAction('discard', $token)
        ->assertNotified('Discarded');

    expect($token->fresh()->discarded_at)->not->toBeNull();
});

it('is not shown on a Codex account', function (): void {
    $account = Account::create(['email' => 'codex@example.com', 'provider' => 'codex', 'name' => 'Codex']);
    CodexCredential::create(['account_id' => $account->id, 'chatgpt_account_id' => 'acct-1']);

    expect(ReserveTokensRelationManager::canViewForRecord($account, EditAccount::class))->toBeFalse();
});
