<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets a user be assigned a role and checked for it', function () {
    Role::create(['name' => 'editor', 'guard_name' => 'web']);
    $user = User::factory()->create();

    $user->assignRole('editor');

    expect($user->hasRole('editor'))->toBeTrue();
});
