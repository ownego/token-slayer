<?php

use App\Models\Account;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Rebind the encrypter singleton so it re-reads app.key / app.previous_keys.
 */
function rebindEncrypter(): void
{
    app()->forgetInstance('encrypter');
    app()->forgetInstance(Encrypter::class);
    (new EncryptionServiceProvider(app()))->register();
}

test('reencrypt rewraps tokens so they survive dropping the previous key', function () {
    $account = Account::factory()->connected()->create();
    $original = 'sk-ant-oat01-ORIGINALSECRET';
    $account->oauth_access_token = $original;
    $account->save();

    $keyA = config('app.key');
    $keyB = 'base64:'.base64_encode(random_bytes(32));

    // Rotate: keyB current, keyA kept as a previous key so old ciphertext still reads.
    config(['app.key' => $keyB, 'app.previous_keys' => [$keyA]]);
    rebindEncrypter();

    expect($account->fresh()->oauth_access_token)->toBe($original);

    $this->artisan('accounts:reencrypt-oauth-tokens')->assertSuccessful();

    // Drop the old key entirely — the token must still decrypt, proving it was
    // re-wrapped under keyB by the command.
    config(['app.previous_keys' => []]);
    rebindEncrypter();

    expect($account->fresh()->oauth_access_token)->toBe($original);
});

test('reencrypt changes the stored ciphertext without altering the plaintext', function () {
    $account = Account::factory()->connected()->create();
    $before = DB::table('accounts')->where('id', $account->id)->value('oauth_access_token');

    $this->artisan('accounts:reencrypt-oauth-tokens')->assertSuccessful();

    $after = DB::table('accounts')->where('id', $account->id)->value('oauth_access_token');

    expect($after)->not->toBe($before)
        ->and($account->fresh()->oauth_access_token)->toBe($account->oauth_access_token);
});

test('reencrypt output carries no token material', function () {
    Account::factory()->connected()->create();

    $this->artisan('accounts:reencrypt-oauth-tokens')
        ->expectsOutputToContain('re-encrypted tokens for')
        ->doesntExpectOutputToContain('sk-ant')
        ->assertSuccessful();
});
