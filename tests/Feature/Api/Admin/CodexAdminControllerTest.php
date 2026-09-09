<?php

use App\Models\Account;
use App\Models\IdeAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

const ADMIN_API_ID_TOKEN_PAYLOAD_B64 = 'eyJlbWFpbCI6ICJzaGFyZWRAZXhhbXBsZS5jb20iLCAiaHR0cHM6Ly9hcGkub3BlbmFpLmNvbS9hdXRoIjogeyJjaGF0Z3B0X2FjY291bnRfaWQiOiAiYWNjdC0xIiwgImNoYXRncHRfdXNlcl9pZCI6ICJ1c2VyLTEiLCAiY2hhdGdwdF9wbGFuX3R5cGUiOiAicHJvIn19';
const ADMIN_API_ACCESS_TOKEN_PAYLOAD_B64 = 'eyJleHAiOiA0MTAyNDQ0ODAwfQ';

function fakeCodexAuthJsonBody(): array
{
    return [
        'auth_mode' => 'chatgpt',
        'OPENAI_API_KEY' => null,
        'tokens' => [
            'id_token' => 'h.'.ADMIN_API_ID_TOKEN_PAYLOAD_B64.'.s',
            'access_token' => 'h.'.ADMIN_API_ACCESS_TOKEN_PAYLOAD_B64.'.s',
            'refresh_token' => 'opaque-refresh-fixture',
            'account_id' => 'acct-1',
        ],
        'last_refresh' => '2026-01-01T00:00:00Z',
    ];
}

function adminBearerHeader(): array
{
    Role::create(['name' => 'admin']);
    $user = User::factory()->create();
    $user->assignRole('admin');
    [$plain] = IdeAccessToken::issueAdminBearer($user);

    return ['Authorization' => "Bearer {$plain}"];
}

it('connects a Codex account via the admin API', function (): void {
    $response = $this->withHeaders(adminBearerHeader())->postJson('/api/admin/codex/connect', [
        'name' => 'Company ChatGPT',
        'auth_json' => fakeCodexAuthJsonBody(),
    ]);

    $response->assertOk()->assertJson(['message' => 'Connected: Company ChatGPT']);
    expect(Account::where('provider', 'codex')->where('name', 'Company ChatGPT')->exists())->toBeTrue();
});

it('provisions a device via the admin API', function (): void {
    $headers = adminBearerHeader();
    $this->withHeaders($headers)->postJson('/api/admin/codex/connect', [
        'name' => 'Company ChatGPT',
        'auth_json' => fakeCodexAuthJsonBody(),
    ]);
    User::factory()->create(['email' => 'employee@example.com']);

    $response = $this->withHeaders($headers)->postJson('/api/admin/codex/provision', [
        'account' => 'Company ChatGPT',
        'email' => 'employee@example.com',
        'auth_json' => fakeCodexAuthJsonBody(),
    ]);

    $response->assertOk()->assertJson(['message' => 'Provisioned for employee@example.com']);
});

it('rejects both endpoints without a valid admin bearer', function (): void {
    $this->postJson('/api/admin/codex/connect', ['name' => 'x', 'auth_json' => []])->assertStatus(401);
    $this->postJson('/api/admin/codex/provision', ['account' => 'x', 'email' => 'a@b.com', 'auth_json' => []])->assertStatus(401);
});

it('returns 404 provisioning to an unknown account name', function (): void {
    User::factory()->create(['email' => 'employee@example.com']);

    $response = $this->withHeaders(adminBearerHeader())->postJson('/api/admin/codex/provision', [
        'account' => 'Nonexistent',
        'email' => 'employee@example.com',
        'auth_json' => fakeCodexAuthJsonBody(),
    ]);

    $response->assertStatus(404);
});
