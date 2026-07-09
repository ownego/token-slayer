<?php

use App\Livewire\Profile;
use App\Models\Account;
use App\Models\Boss;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('profile redirects guests to the slack login route', function () {
    $this->get('/profile')->assertRedirect(route('slack.login'));
});

test('profile shows a link to the battlefield page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Battlefield', escape: false)
        ->assertSee('href="'.route('battlefield').'"', escape: false);
});

test('profile shows the plain token once when redirected from oauth', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('plain-abc')
        ->assertSee($user->display_name);
});

test('profile surfaces a curl-pipe-sh installer pointed at the install.sh route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('curl -fsSL')
        ->assertSee(route('install-script'));
});

test('profile bakes the fresh plain token into the combined install command', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('curl -fsSL '.route('install-script').' | TOKEN_SLAYER_TOKEN=plain-abc sh', escape: false);
});

test('profile still shows the TOKEN_SLAYER_TOKEN command shape with a placeholder when no fresh token is in session', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('TOKEN_SLAYER_TOKEN=&lt;your-token&gt; sh', escape: false)
        ->assertDontSee('plain-abc');
});

test('manual hook config shows a step that writes the token to the config file', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee("printf '%s' 'plain-abc' > ~/.config/token_slayer/token")
        ->assertSee('chmod 600 ~/.config/token_slayer/token');
});

test('profile reflects the configured hook namespace in displayed paths and the install command', function () {
    config(['app.hook_namespace' => 'acme']);
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('curl -fsSL '.route('install-script').' | ACME_TOKEN=plain-abc sh', escape: false)
        ->assertSee('~/.config/acme/token')
        ->assertDontSee('token_slayer')
        ->assertDontSee('TOKEN_SLAYER_TOKEN');
});

test('regenerate replaces the hook token', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'old')]);
    $original = $user->hook_token;

    Livewire::actingAs($user)
        ->test(Profile::class)
        ->call('regenerate');

    expect($user->fresh()->hook_token)->not->toBe($original);
});

test('manual hook config shows Antigravity configuration', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('~/.gemini/config/hooks.json')
        ->assertSee('PROVIDER=antigravity');
});

test('profile offers three independent install tracks', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('Claude chat')                              // track 1: browser/Desktop
        ->assertSee(route('userscript'))                        // userscript install link
        ->assertSee('CLI')                                      // track 1: CLIs
        ->assertSee(route('install-script'))
        ->assertSee('Claude Cowork')                            // track 3: cowork
        ->assertSee(route('cowork-install-script'));
});

test('profile bakes the token into the standalone cowork install command', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user)->withSession(['hook_token_plain' => 'plain-abc']);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('curl -fsSL '.route('cowork-install-script').' | TOKEN_SLAYER_TOKEN=plain-abc sh', escape: false);
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
    $account = Account::factory()->create(['email' => 'team-rocket@example.com', 'plan' => 'max-20x']);
    $user = User::factory()->create(['account_id' => $account->id]);
    User::factory()->create(['account_id' => $account->id]);
    $this->actingAs($user);

    Event::factory()->create(['user_id' => $user->id, 'tokens' => 55, 'created_at' => now()->subMinutes(10)]);

    $this->get('/profile')
        ->assertOk()
        ->assertSee('team-rocket@example.com')
        ->assertSee('max-20x')
        ->assertSee(number_format(55));
});

test('profile hides the my-account block when the user has no account', function () {
    $user = User::factory()->create(['account_id' => null]);
    $this->actingAs($user);

    $this->get('/profile')
        ->assertOk()
        ->assertDontSee('My account');
});
