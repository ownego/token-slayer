<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('admin usage route redirects guests to slack login', function () {
    $this->get('/admin/usage')->assertRedirect(route('slack.login'));
});

test('non-admin users are forbidden from the admin usage page', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/admin/usage')->assertForbidden();
});

test('a role without view_usage_analytics is forbidden from the admin usage page even with another permission', function () {
    Permission::firstOrCreate(['name' => 'ViewAny:Account', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:Account');

    $user = User::factory()->create();
    $user->assignRole('account_viewer');

    $this->actingAs($user)
        ->get('/admin/usage')
        ->assertForbidden();
});

test('a role with view_usage_analytics can view the admin usage page', function () {
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    $role = Role::create(['name' => 'usage_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('view_usage_analytics');

    $user = User::factory()->create();
    $user->assignRole('usage_viewer');

    $this->actingAs($user)
        ->get('/admin/usage')
        ->assertOk();
});

test('admin users can view the admin usage page', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/admin/usage')
        ->assertOk()
        ->assertSee('Usage by account')
        ->assertSee('Usage by user');
});

test('admin usage renders account and user rows with token figures', function () {
    $account = Account::factory()->create(['email' => 'team-a@example.com', 'plan' => 'max-20x']);
    $member = User::factory()->create(['slack_handle' => 'member-one']);
    $admin = User::factory()->admin()->create(['slack_handle' => 'the-admin']);
    $account->users()->attach([$member->id, $admin->id]);

    Event::factory()->create(['user_id' => $member->id, 'account_id' => $account->id, 'tokens' => 1234, 'created_at' => now()->subMinutes(20)]);

    $this->actingAs($admin)
        ->get('/admin/usage')
        ->assertOk()
        ->assertSee('team-a@example.com')
        ->assertSee('member-one')
        ->assertSee(number_format(1234));
});
