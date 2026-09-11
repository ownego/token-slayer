<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the quick-update page redirects guests to the slack login route', function () {
    $this->get('/update')->assertRedirect(route('slack.login'));
});

test('the quick-update page shows the reinstall commands for both platforms, with no setup wizard steps', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/update')
        ->assertOk()
        ->assertSee(route('install-script'))
        ->assertSee(route('install-script-ps1'))
        ->assertDontSee('Choose your platform')
        ->assertDontSee('Check Python')
        ->assertDontSee('Do you already have a token?');
});

test('the quick-update page shows a separate cmd.exe command for Windows, not just PowerShell', function () {
    // A real user ran the PowerShell one-liner inside cmd.exe (prompt style
    // gave it away) and got "The filename, directory name, or volume label
    // syntax is incorrect" -- cmd.exe cannot parse `irm ... | iex` at all.
    // The full setup wizard already offers both; this page was missing the
    // cmd.exe fallback entirely.
    $this->actingAs(User::factory()->create());

    $this->get('/update')
        ->assertOk()
        ->assertSee('cmd.exe')
        ->assertSee('powershell -ExecutionPolicy ByPass -c', escape: false)
        ->assertSeeInOrder(['PowerShell:', 'irm', 'cmd.exe', 'powershell -ExecutionPolicy']);
});
