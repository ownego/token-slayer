<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('creates the super_admin role during migration', function () {
    expect(Role::where('name', 'super_admin')->where('guard_name', 'web')->exists())->toBeTrue();
});
