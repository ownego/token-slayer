<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Re-encrypts every stored OAuth grant under the CURRENT `APP_KEY`. This is
 * the at-rest kill switch given Anthropic exposes no token revocation
 * endpoint: rotate the key (old key moves to `APP_PREVIOUS_KEYS` so existing
 * ciphertext still decrypts during the transition), deploy, run this command
 * to re-wrap every token under the new key, then drop the old key from
 * `APP_PREVIOUS_KEYS` on the next deploy.
 *
 * The command reads each token through the model's `encrypted` cast (which
 * decrypts with the current-or-previous keys) and re-saves it (which encrypts
 * with the current key). It is idempotent and never emits token material.
 */
#[Signature('accounts:reencrypt-oauth-tokens')]
#[Description('Re-encrypt stored account OAuth tokens under the current APP_KEY (run after key rotation)')]
class ReencryptAccountTokens extends Command
{
    /**
     * Re-save every account holding an access or refresh token so its
     * encrypted columns are rewritten under the current key.
     *
     * @return int the command exit code
     */
    public function handle(): int
    {
        $count = 0;

        Account::query()
            ->where(function ($query): void {
                $query->whereNotNull('oauth_access_token')
                    ->orWhereNotNull('oauth_refresh_token');
            })
            ->each(function (Account $account) use (&$count): void {
                // The model's `encrypted` cast compares DECRYPTED values for
                // dirtiness, so re-saving the same plaintext is a no-op and
                // would NOT re-wrap the ciphertext. Read the plaintext through
                // the cast (decrypts via current-or-previous keys), then write a
                // freshly-encrypted value (Crypt uses the current key) straight
                // to the column to force the re-wrap.
                $access = $account->oauth_access_token;
                $refresh = $account->oauth_refresh_token;

                DB::table('accounts')->where('id', $account->getKey())->update([
                    'oauth_access_token' => $access === null ? null : Crypt::encryptString($access),
                    'oauth_refresh_token' => $refresh === null ? null : Crypt::encryptString($refresh),
                ]);
                $count++;
            });

        $this->info("re-encrypted tokens for {$count} accounts");

        return self::SUCCESS;
    }
}
