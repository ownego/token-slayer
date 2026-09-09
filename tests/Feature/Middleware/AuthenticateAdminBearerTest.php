<?php

use App\Http\Middleware\AuthenticateAdminBearer;
use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('accepts a valid admin bearer token from a user holding a role', function (): void {
    Role::create(['name' => 'admin']);
    $user = User::factory()->create();
    $user->assignRole('admin');
    [$plain] = IdeAccessToken::issueAdminBearer($user);

    Route::middleware(AuthenticateAdminBearer::class)->get('/__test-admin-bearer', fn (Request $r) => response()->json(['user_id' => $r->user()->id]));

    $response = $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/__test-admin-bearer');

    $response->assertOk()->assertJson(['user_id' => $user->id]);
});

it('rejects a bearer token from a user holding no role', function (): void {
    $user = User::factory()->create();
    [$plain] = IdeAccessToken::issueAdminBearer($user);

    Route::middleware(AuthenticateAdminBearer::class)->get('/__test-admin-bearer', fn () => response()->json(['ok' => true]));

    $this->withHeader('Authorization', "Bearer {$plain}")->getJson('/__test-admin-bearer')->assertStatus(403);
});

it('rejects a missing or unknown bearer token', function (): void {
    Route::middleware(AuthenticateAdminBearer::class)->get('/__test-admin-bearer', fn () => response()->json(['ok' => true]));

    $this->getJson('/__test-admin-bearer')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer not-a-real-token')->getJson('/__test-admin-bearer')->assertStatus(401);
});

it('rejects an ide bearer token — kinds do not cross-authenticate', function (): void {
    $user = User::factory()->create();
    [$idePlain] = IdeAccessToken::issueBearer($user);

    Route::middleware(AuthenticateAdminBearer::class)->get('/__test-admin-bearer', fn () => response()->json(['ok' => true]));

    $this->withHeader('Authorization', "Bearer {$idePlain}")->getJson('/__test-admin-bearer')->assertStatus(401);
});
