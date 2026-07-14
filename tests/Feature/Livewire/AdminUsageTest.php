<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin usage route redirects guests to slack login', function () {
    $this->get('/admin/usage')->assertRedirect(route('slack.login'));
});

test('non-admin users are forbidden from the admin usage page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin/usage')->assertForbidden();
});

test('admin users can view the admin usage page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get('/admin/usage')
        ->assertOk()
        ->assertSee('Usage by account')
        ->assertSee('Usage by user');
});

test('admin usage renders account and user rows with token figures', function () {
    $account = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    $member = User::factory()->create(['slack_handle' => 'member-one']);
    $admin = User::factory()->create(['is_admin' => true, 'slack_handle' => 'the-admin']);
    $account->users()->attach([$member->id, $admin->id]);

    Event::factory()->create(['user_id' => $member->id, 'account_id' => $account->id, 'tokens' => 1234, 'created_at' => now()->subMinutes(20)]);

    $this->actingAs($admin)
        ->get('/admin/usage')
        ->assertOk()
        ->assertSee('team-a@example.com')
        ->assertSee('member-one')
        ->assertSee(number_format(1234));
});
