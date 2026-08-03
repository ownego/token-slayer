<?php

use App\Enums\AccountPlan;
use App\Livewire\Profile;
use App\Models\Account;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('profile redirects guests to the slack login route', function () {
    $this->get('/profile')->assertRedirect(route('slack.login'));
});

test('profile shows the shared account nav with profile active', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/profile')
        ->assertOk()
        ->assertSee('href="'.route('profile').'"', escape: false)
        ->assertSee('href="'.route('setup').'"', escape: false)
        ->assertSee('href="'.route('guide').'"', escape: false);
});

test('profile shows a link to the battlefield page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Battlefield', escape: false)
        ->assertSee('href="'.route('battlefield').'"', escape: false);
});

test('profile shows a link to the dashboard panel', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Dashboard', escape: false)
        ->assertSee('href="'.route('filament.admin.pages.dashboard').'"', escape: false);
});

test('profile shows the plain token once when redirected from oauth', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('plain-abc')
        ->assertSee($user->display_name);
});

test('regenerate replaces the hook token', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'old')]);
    $original = $user->hook_token;

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->call('regenerate');

    expect($user->fresh()->hook_token)->not->toBe($original);
});

test('regenerate token button is gated behind a confirmation step', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Regenerate token')
        ->assertSee('confirmingRegenerate = true', escape: false)
        ->assertSee('Yes, regenerate')
        ->assertSee('Cancel');
});

test('profile shows the players own all-time, monthly, and daily damage totals', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $boss = Boss::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $boss->id, 'tokens' => 100, 'created_at' => now()->subHour()]);
    Event::factory()->create(['user_id' => $user->id, 'boss_id' => $boss->id, 'tokens' => 25, 'created_at' => now()->subDays(45)]);
    Event::factory()->create(['user_id' => $other->id, 'boss_id' => $boss->id, 'tokens' => 999, 'created_at' => now()->subHour()]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Battlefield stats')
        ->assertSee('All-time')
        ->assertSee('125')   // user's all-time (100 + 25), excludes the other player
        ->assertSee('100')   // user's monthly and daily
        ->assertDontSee('999');
});

test('profile shows community and personal usage across hourly, daily, monthly', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'tokens' => 40, 'created_at' => now()->subMinutes(30)]);
    Event::factory()->create(['user_id' => $other->id, 'tokens' => 60, 'created_at' => now()->subMinutes(30)]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('All users')
        ->assertSee('Hourly')
        ->assertSee(number_format(100)) // community hourly
        ->assertSee(number_format(40)); // personal hourly
});

test('profile shows the my-account block when the user has an account', function () {
    $account = Account::factory()->create(['email' => 'team-rocket@example.com', 'plan' => AccountPlan::Max20x]);
    $user = User::factory()->create();
    $account->users()->attach([$user->id, User::factory()->create()->id]);
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'tokens' => 55, 'created_at' => now()->subMinutes(10)]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('team-rocket@example.com')
        ->assertSee('Max 20x')
        ->assertSee(number_format(55));
});

test('profile hides the my-account block when the user has no account', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('My account');
});

it('lists every account the user is a member of with their own usage', function () {
    $user = User::factory()->create();
    [$a, $b] = Account::factory()->count(2)->create();
    $user->accounts()->attach([$a->id, $b->id]);
    Event::factory()->create(['user_id' => $user->id, 'account_id' => $a->id, 'tokens' => 42]);

    Livewire::actingAs($user)->test(Profile::class)
        ->assertSee($a->email)
        ->assertSee($b->email);
});

it('shows attribution status from the latest event', function () {
    // CachedLatestVersion skips its GitHub round trip unless github.token/
    // github.cli_repo are set (see tests/Feature/ProfileVersionBadgeTest.php
    // for the canonical fake), so the outdated-client hint needs a fake
    // release here too or it never renders.
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v3',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $user = User::factory()->create(['client_version' => '1']);
    Event::factory()->create([
        'user_id' => $user->id, 'account_id' => null,
        'account_email' => 'mystery@gmail.com', 'account_source' => 'auto',
    ]);

    Livewire::actingAs($user)->test(Profile::class)
        ->assertSee('mystery@gmail.com')
        ->assertSee('token-slayer update'); // outdated client hint (latest is 3)
});

it('shows the matched attribution status for an org-uuid verified event with no email', function () {
    $account = Account::factory()->create(['email' => 'org@ownego.com']);
    $user = User::factory()->create();
    Event::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'account_email' => null,
        'account_source' => 'credential',
        'account_org_id' => 'org-x',
    ]);

    Livewire::actingAs($user)->test(Profile::class)
        ->assertSee('an org account')
        ->assertSee('org@ownego.com');
});

test('regenerating the token warns that existing installs will 401 until updated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('every machine currently using it will stop working', escape: false);
});

test('profile links to the setup wizard and the CLI guide', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/profile')
        ->assertOk()
        ->assertSee('href="'.route('setup').'"', escape: false)
        ->assertSee('href="'.route('guide').'"', escape: false);
});

test('profile does not double-wrap the account nav in its own width constraint', function () {
    $this->actingAs(User::factory()->create());

    $html = $this->get('/profile')->getContent();

    $navPos = strpos($html, '<nav');
    $wrapperPos = strpos($html, 'p-8 max-w-3xl mx-auto space-y-6');

    expect($navPos)->not->toBeFalse()
        ->and($wrapperPos)->not->toBeFalse()
        ->and($navPos)->toBeLessThan($wrapperPos);
});
