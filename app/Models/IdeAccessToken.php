<?php

namespace App\Models;

use Database\Factories\IdeAccessTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IdeAccessToken extends Model
{
    /** @use HasFactory<IdeAccessTokenFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{0: string, 1: self}
     */
    public static function issueOneTime(User $user, string $state, int $ttlSeconds): array
    {
        return self::issueOneTimeOfKind('one_time', $user, $state, $ttlSeconds);
    }

    public static function consumeOneTime(string $plain, string $state): ?User
    {
        return self::consumeOneTimeOfKind('one_time', $plain, $state);
    }

    /**
     * Same shape as issueOneTime(), for the CCRC hub login flow — kept on
     * its own `kind` so a token minted for one flow can never be redeemed
     * against the other's /api/*\/auth/exchange endpoint.
     *
     * @return array{0: string, 1: self}
     */
    public static function issueOneTimeCcrc(User $user, string $state, int $ttlSeconds): array
    {
        return self::issueOneTimeOfKind('one_time_ccrc', $user, $state, $ttlSeconds);
    }

    public static function consumeOneTimeCcrc(string $plain, string $state): ?User
    {
        return self::consumeOneTimeOfKind('one_time_ccrc', $plain, $state);
    }

    /**
     * @return array{0: string, 1: self}
     */
    private static function issueOneTimeOfKind(string $kind, User $user, string $state, int $ttlSeconds): array
    {
        return self::issue($user, $kind, [
            'state_hash' => hash('sha256', $state),
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    private static function consumeOneTimeOfKind(string $kind, string $plain, string $state): ?User
    {
        $tokenHash = hash('sha256', $plain);

        $consumed = self::atomicConsume(
            self::whereKind($kind)
                ->where('token_hash', $tokenHash)
                ->where('state_hash', hash('sha256', $state))
        );

        return $consumed ? self::whereKindAndHash($kind, $tokenHash)->first()?->user : null;
    }

    /**
     * @return array{0: string, 1: self}
     */
    public static function issueBearer(User $user): array
    {
        return self::issue($user, 'bearer');
    }

    public static function resolveBearer(string $plain): ?User
    {
        $token = self::findActiveBearer($plain);

        if ($token === null) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->user;
    }

    public static function findActiveBearer(string $plain): ?self
    {
        return self::whereKindAndHash('bearer', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();
    }

    /**
     * @return array{0: string, 1: self}
     */
    public static function issueAdminBearer(User $user): array
    {
        return self::issue($user, 'admin_bearer');
    }

    /**
     * Resolve an admin bearer token to its User, but ONLY when that user
     * currently holds at least one role — a token minted while the holder
     * was an admin stops authenticating the moment their role is revoked,
     * not just at the Filament-panel-access layer.
     */
    public static function resolveAdminBearer(string $plain): ?User
    {
        $token = self::whereKindAndHash('admin_bearer', hash('sha256', $plain))
            ->whereNull('revoked_at')
            ->first();

        if ($token === null || ! $token->user->roles()->exists()) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->user;
    }

    /**
     * @return array{0: string, 1: self}
     */
    public static function issueSessionUrl(User $user, string $redirectPath, int $ttlSeconds): array
    {
        return self::issue($user, 'session_url', [
            'redirect_path' => $redirectPath,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);
    }

    /**
     * @return array{user: User, redirectPath: string}|null
     */
    public static function consumeSessionUrl(string $plain): ?array
    {
        $tokenHash = hash('sha256', $plain);

        $consumed = self::atomicConsume(
            self::whereKind('session_url')->where('token_hash', $tokenHash)
        );

        if (! $consumed) {
            return null;
        }

        $token = self::whereKindAndHash('session_url', $tokenHash)->first();

        return $token ? ['user' => $token->user, 'redirectPath' => $token->redirect_path ?? '/'] : null;
    }

    public function revoke(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    protected static function newFactory(): IdeAccessTokenFactory
    {
        return IdeAccessTokenFactory::new();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: string, 1: self}
     */
    private static function issue(User $user, string $kind, array $attributes = []): array
    {
        $plain = Str::random(64);

        $token = self::create([
            'user_id' => $user->id,
            'kind' => $kind,
            'token_hash' => hash('sha256', $plain),
            ...$attributes,
        ]);

        return [$plain, $token];
    }

    /**
     * Atomically mark a single unconsumed, unexpired token as consumed.
     * Returns true when exactly one row was updated.
     */
    private static function atomicConsume(Builder $query): bool
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]) > 0;
    }

    private static function whereKind(string $kind): Builder
    {
        return self::query()->where('kind', $kind);
    }

    private static function whereKindAndHash(string $kind, string $tokenHash): Builder
    {
        return self::whereKind($kind)->where('token_hash', $tokenHash);
    }
}
