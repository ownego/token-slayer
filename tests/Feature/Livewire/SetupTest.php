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
