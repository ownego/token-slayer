<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('adds an is_default flag to roles defaulting to false', function () {
    expect(Schema::hasColumn('roles', 'is_default'))->toBeTrue();

    $role = Role::create(['name' => 'viewer', 'guard_name' => 'web']);

    expect((bool) $role->fresh()->is_default)->toBeFalse();
});
