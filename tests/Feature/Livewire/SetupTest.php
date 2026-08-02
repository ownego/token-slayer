<?php

use App\Livewire\Setup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('setup redirects guests to the slack login route', function () {
    $this->get('/setup')->assertRedirect(route('slack.login'));
});

test('setup shows the three track choices', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('Claude CLI')
        ->assertSee('Claude chat')
        ->assertSee('Claude Cowork');
});

test('setup renders as a livewire full-page component', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Setup::class)->assertOk();
});

test('cli track step 2 offers macOS, Linux, and Windows', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSeeInOrder(['macOS', 'Linux', 'Windows']);
});

test('cli track step 3 checks python and flags the broken 3.14 homebrew bottle', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('python3 --version')
        ->assertSee('3.10')
        ->assertSee('3.14')
        ->assertSee('brew install python@3.12')
        ->assertSee('python3.12 --version')
        ->assertSee('brew --version');
});

test('cli track python fix branch never suggests re-running the homebrew installer unconditionally', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')->assertOk()->assertDontSee('.zshrc');
});

test('cli track step 4 offers three token states', function () {
    config(['app.hook_namespace' => 'token_slayer']);
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('token-slayer status')
        ->assertSee('cat ~/.config/token_slayer/token')
        ->assertSee('Get-Content $HOME\.config\token_slayer\token', escape: false);
});

test('generateToken mints a fresh token via HookTokenRotator', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'old')]);
    $original = $user->hook_token;

    Livewire::actingAs($user)
        ->test(Setup::class)
        ->call('generateToken');

    expect($user->fresh()->hook_token)->not->toBe($original);
});

test('cli track install step shows commands for all three platforms with the fresh token embedded', function () {
    config(['app.hook_namespace' => 'token_slayer']);
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user);

    Livewire::test(Setup::class)
        ->call('generateToken')
        ->assertSee('TOKEN_SLAYER_TOKEN=', escape: false);
});

test('cli track verify step mentions the tok alias and links to the guide', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('token-slayer status')
        ->assertSee('alias ngắn')
        ->assertSee('href="'.route('guide').'"', escape: false);
});

test('cli track offers the manual hook config fallback with all three provider snippets', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('UserPromptSubmit') // claude snippet
        ->assertSee('config.toml') // codex snippet
        ->assertSee('hooks.json'); // antigravity snippet
});

test('cowork track offers macOS and Windows only, not Linux', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSeeInOrder(['macOS', 'Windows'])
        ->assertSee('không gắn được account cụ thể');
});

test('cowork track shows the attribution caveat', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('không gắn được account cụ thể')
        ->assertSee('5h/7d');
});

test('cowork track bakes the token into the cowork install command', function () {
    $user = User::factory()->create(['hook_token' => hash('sha256', 'plain-abc')]);
    $this->actingAs($user);

    Livewire::test(Setup::class)
        ->call('generateToken')
        ->assertSee('curl -fsSL '.route('cowork-install-script'), escape: false);
});

test('claude chat track lists tampermonkey, the chrome flag, and the userscript install', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/setup')
        ->assertOk()
        ->assertSee('Tampermonkey')
        ->assertSee('Allow user scripts')
        ->assertSee(route('userscript'));
});
