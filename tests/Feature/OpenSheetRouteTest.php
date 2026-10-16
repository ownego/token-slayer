<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the open-sheet link redirects to a clean battlefield URL and flashes what to open', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('battlefield.open-sheet', 'editor'))
        ->assertRedirect(route('battlefield'))
        ->assertSessionHas('open_sheet', ['tab' => 'character', 'editor' => true]);
});

test('the character tab can be opened without the editor', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('battlefield.open-sheet', 'character'))
        ->assertSessionHas('open_sheet', ['tab' => 'character', 'editor' => false]);
});

test('an unknown target is a 404 and a guest is sent to sign in', function () {
    $this->actingAs(User::factory()->create())->get('/battlefield/open/nonsense')->assertNotFound();
    auth()->logout();
    $this->get(route('battlefield.open-sheet', 'editor'))->assertRedirect();
});

test('the sheet opens once after the link, and a reload shows the plain arena', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get(route('battlefield.open-sheet', 'editor'));

    $this->get(route('battlefield'))
        ->assertSee('data-open-sheet="character"', escape: false)
        ->assertSee('data-open-editor="1"', escape: false);

    $this->get(route('battlefield'))->assertDontSee('data-open-sheet', escape: false);
});

test('an old ?sheet= URL no longer opens anything: only the one-time flash does', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get(route('battlefield').'?sheet=character&editor=1')
        ->assertOk()
        ->assertDontSee('data-open-sheet', escape: false);
});
