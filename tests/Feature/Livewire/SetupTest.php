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
