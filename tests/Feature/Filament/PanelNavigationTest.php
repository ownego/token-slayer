<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('offers a way back to the battlefield from inside the panel', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get('/dashboard')
        ->assertOk()
        ->assertSee(route('battlefield'), escape: false)
        ->assertSee('Battlefield', escape: false);
});

it('labels the battlefield link once, not once per responsive variant', function () {
    $this->actingAs(User::factory()->admin()->create());

    $html = $this->get('/dashboard')->assertOk()->getContent();

    // The panel ships its own Tailwind build containing only the utilities
    // Filament itself uses — `hidden` and `sr-only` are absent, so a
    // show-one/hide-the-other pair renders both labels visibly.
    expect(substr_count($html, route('battlefield')))->toBe(1)
        ->and(substr_count($html, '>Battlefield<'))->toBe(1);
});
