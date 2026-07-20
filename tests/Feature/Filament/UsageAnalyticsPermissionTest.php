<?php

use App\Filament\Pages\UnrecognizedAccounts;
use App\Filament\Pages\UsageAnalytics;
use App\Filament\Widgets\ActivityHeatmap;
use App\Filament\Widgets\FleetQuotaOverview;
use App\Filament\Widgets\TokenVolumeChart;
use App\Filament\Widgets\TopAccountsLeaderboard;
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

it('gates each analytics widget by its own View permission, not the shared page permission', function (string $widget, string $permission) {
    Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

    // A user who can open the page but was not granted THIS widget must not see it.
    $pageOnly = Role::create(['name' => 'page_only_'.class_basename($widget), 'guard_name' => 'web']);
    $pageOnly->givePermissionTo('view_usage_analytics');
    $pageOnlyUser = User::factory()->create();
    $pageOnlyUser->assignRole($pageOnly);

    // A user granted exactly this widget's own permission sees it.
    $widgetRole = Role::create(['name' => 'widget_'.class_basename($widget), 'guard_name' => 'web']);
    $widgetRole->givePermissionTo($permission);
    $widgetUser = User::factory()->create();
    $widgetUser->assignRole($widgetRole);

    $this->actingAs(User::factory()->create());
    expect($widget::canView())->toBeFalse();

    $this->actingAs($pageOnlyUser);
    expect($widget::canView())->toBeFalse();

    $this->actingAs($widgetUser);
    expect($widget::canView())->toBeTrue();
})->with([
    'FleetQuotaOverview' => [FleetQuotaOverview::class, 'View:FleetQuotaOverview'],
    'ActivityHeatmap' => [ActivityHeatmap::class, 'View:ActivityHeatmap'],
    'TokenVolumeChart' => [TokenVolumeChart::class, 'View:TokenVolumeChart'],
    'TopUsersLeaderboard' => [TopUsersLeaderboard::class, 'View:TopUsersLeaderboard'],
    'TopAccountsLeaderboard' => [TopAccountsLeaderboard::class, 'View:TopAccountsLeaderboard'],
]);
