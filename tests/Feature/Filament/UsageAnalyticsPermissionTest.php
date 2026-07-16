<?php

use App\Filament\Pages\UnrecognizedAccounts;
use App\Filament\Pages\UsageAnalytics;
use App\Filament\Widgets\TopUsersLeaderboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'view_usage_analytics', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'ViewAny:Account', 'guard_name' => 'web']);
});

it('blocks a role without view_usage_analytics from the usage analytics page', function () {
    $role = Role::create(['name' => 'account_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:Account');

    $user = User::factory()->create();
    $user->assignRole('account_viewer');

    $this->actingAs($user)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('blocks a role without view_usage_analytics from the unrecognized accounts page', function () {
    $role = Role::create(['name' => 'account_viewer_unrecognized', 'guard_name' => 'web']);
    $role->givePermissionTo('ViewAny:Account');

    $user = User::factory()->create();
    $user->assignRole('account_viewer_unrecognized');

    $this->actingAs($user)
        ->get(UnrecognizedAccounts::getUrl(panel: 'admin'))
        ->assertForbidden();
});

it('lets a role with view_usage_analytics reach the usage analytics page', function () {
    $role = Role::create(['name' => 'analytics_viewer', 'guard_name' => 'web']);
    $role->givePermissionTo('view_usage_analytics');

    $user = User::factory()->create();
    $user->assignRole('analytics_viewer');

    $this->actingAs($user)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets a role with view_usage_analytics reach the unrecognized accounts page', function () {
    $role = Role::create(['name' => 'analytics_viewer_unrecognized', 'guard_name' => 'web']);
    $role->givePermissionTo('view_usage_analytics');

    $user = User::factory()->create();
    $user->assignRole('analytics_viewer_unrecognized');

    $this->actingAs($user)
        ->get(UnrecognizedAccounts::getUrl(panel: 'admin'))
        ->assertOk();
});

it('lets a super_admin reach both pages via the Gate::before bypass', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(UsageAnalytics::getUrl(panel: 'admin'))
        ->assertOk();

    $this->actingAs($admin)
        ->get(UnrecognizedAccounts::getUrl(panel: 'admin'))
        ->assertOk();
});

it('gates the TopUsersLeaderboard widget by the same permission', function () {
    $role = Role::create(['name' => 'analytics_viewer_widget', 'guard_name' => 'web']);
    $role->givePermissionTo('view_usage_analytics');

    $noPermUser = User::factory()->create();
    $permUser = User::factory()->create();
    $permUser->assignRole('analytics_viewer_widget');

    $this->actingAs($noPermUser);
    expect(TopUsersLeaderboard::canView())->toBeFalse();

    $this->actingAs($permUser);
    expect(TopUsersLeaderboard::canView())->toBeTrue();
});
