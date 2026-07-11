<?php

use App\Enums\MembershipStatus;
use App\Models\Account;
use App\Models\User;
use App\Services\Accounts\AccountMembershipRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('materializes a new pair as untracked', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();

    app(AccountMembershipRecorder::class)->record($user->id, $account->id);

    $row = DB::table('account_user')->where(['account_id' => $account->id, 'user_id' => $user->id])->first();
    expect($row->status)->toBe(MembershipStatus::Untracked->value);
});

it('is idempotent and never downgrades a tracked row', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['status' => MembershipStatus::Tracked->value]);

    app(AccountMembershipRecorder::class)->record($user->id, $account->id);
    app(AccountMembershipRecorder::class)->record($user->id, $account->id);

    expect(DB::table('account_user')->where(['account_id' => $account->id, 'user_id' => $user->id])->count())->toBe(1);
    expect(DB::table('account_user')->where(['account_id' => $account->id, 'user_id' => $user->id])->value('status'))
        ->toBe(MembershipStatus::Tracked->value);
});
