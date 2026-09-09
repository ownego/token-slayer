<?php

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('the Connect Codex account header action starts a device-code attempt and shows the user_code', function (): void {
    $admin = User::factory()->admin()->create();
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234', 'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->mountAction('connectCodexAccount')
        ->assertActionDataSet([
            'user_code' => 'ABCD-1234',
            'verification_url' => 'https://auth.openai.com/codex/device',
        ]);
});

it('clicking "Check now" while still pending notifies and keeps the attempt open, without creating an account', function (): void {
    $admin = User::factory()->admin()->create();
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234', 'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'error' => ['code' => 'deviceauth_authorization_pending'],
        ], 400),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->mountAction('connectCodexAccount')
        ->setActionData(['name' => 'Company ChatGPT'])
        ->callMountedAction()
        ->assertNotified();

    expect(Account::where('provider', 'codex')->count())->toBe(0);
});

it('clicking "Check now" once approved connects the account and notifies success', function (): void {
    $admin = User::factory()->admin()->create();
    Http::fake([
        'auth.openai.com/api/accounts/deviceauth/usercode' => Http::response([
            'device_auth_id' => 'da-1', 'user_code' => 'ABCD-1234', 'interval' => 5,
            'expires_at' => now()->addMinutes(15)->toIso8601String(),
        ], 200),
        'auth.openai.com/api/accounts/deviceauth/token' => Http::response([
            'status' => 'success', 'authorization_code' => 'code-1',
            'code_challenge' => 'chal-1', 'code_verifier' => 'verifier-1',
        ], 200),
        'auth.openai.com/oauth/token' => Http::response([
            'access_token' => 'h.eyJlbWFpbCI6ICJzaGFyZWRAZXhhbXBsZS5jb20iLCAiaHR0cHM6Ly9hcGkub3BlbmFpLmNvbS9hdXRoIjogeyJjaGF0Z3B0X2FjY291bnRfaWQiOiAiYWNjdC0xIn19.s',
            'id_token' => 'h.eyJlbWFpbCI6ICJzaGFyZWRAZXhhbXBsZS5jb20iLCAiaHR0cHM6Ly9hcGkub3BlbmFpLmNvbS9hdXRoIjogeyJjaGF0Z3B0X2FjY291bnRfaWQiOiAiYWNjdC0xIn19.s',
            'refresh_token' => 'refresh-1',
            'expires_in' => 864000,
        ], 200),
    ]);

    Livewire::actingAs($admin)
        ->test(ListAccounts::class)
        ->mountAction('connectCodexAccount')
        ->setActionData(['name' => 'Company ChatGPT'])
        ->callMountedAction()
        ->assertNotified();

    expect(Account::where('provider', 'codex')->where('email', 'shared@example.com')->exists())->toBeTrue();
});
