<?php

use App\Livewire\AdminToken;
use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('generates and shows a plaintext admin bearer token once for a role-holding user', function (): void {
    Role::create(['name' => 'admin']);
    $user = User::factory()->create();
    $user->assignRole('admin');

    Livewire::actingAs($user)
        ->test(AdminToken::class)
        ->assertSet('plainToken', null)
        ->call('generateToken')
        ->assertSet('plainToken', fn (?string $token) => $token !== null && strlen($token) === 64);

    expect(IdeAccessToken::where('user_id', $user->id)->where('kind', 'admin_bearer')->count())->toBe(1);
});

it('is forbidden for a user holding no role', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin-token')->assertForbidden();
});

it('requires authentication', function (): void {
    $this->get('/admin-token')->assertRedirect('/auth/slack');
});
