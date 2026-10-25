<?php

use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\CustomCharacterVersion;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Expects the attempt to be refused by a unique index. It runs in its own savepoint, so
 * on Postgres the refusal does not poison the transaction the test runs in.
 *
 * @param  Closure(): mixed  $attempt  the write the index must refuse
 * @return void
 */
function expectUniqueRefusal(Closure $attempt): void
{
    expect(fn () => DB::transaction($attempt))->toThrow(UniqueConstraintViolationException::class);
}

/**
 * A fighter draft for the user.
 *
 * @param  User  $user  the owner
 * @param  string  $hash  the draft's version hash
 * @return CustomCharacterDraft
 */
function fighterDraftFor(User $user, string $hash): CustomCharacterDraft
{
    return CustomCharacterDraft::create(['user_id' => $user->id, 'version_hash' => $hash, 'animations' => ['idle' => ['frames' => 6, 'rate' => 8]]]);
}

test('refuses a second current row in the same slot', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->for($user)->create(['slot' => 2]);
    CustomCharacter::factory()->for($user)->create();

    expectUniqueRefusal(fn () => CustomMinion::factory()->for($user)->create(['slot' => 2]));
    expectUniqueRefusal(fn () => CustomCharacter::factory()->for($user)->create());
});

test('refuses a second draft of the same type', function () {
    $user = User::factory()->create();
    fighterDraftFor($user, 'draft0000001');
    CustomMinionDraft::factory()->for($user)->create();

    expectUniqueRefusal(fn () => fighterDraftFor($user, 'draft0000002'));
    expectUniqueRefusal(fn () => CustomMinionDraft::factory()->for($user)->create());
});

test('refuses a repeated hash among saved rows of one type', function () {
    $user = User::factory()->create();
    CustomCharacter::factory()->for($user)->create(['version_hash' => 'abc123def456']);
    CustomMinion::factory()->for($user)->create(['version_hash' => 'minion000001', 'slot' => 1]);

    expectUniqueRefusal(fn () => CustomCharacterVersion::factory()->for($user)->create(['version_hash' => 'abc123def456']));
    expectUniqueRefusal(fn () => CustomMinion::factory()->for($user)->create(['version_hash' => 'minion000001', 'slot' => 2]));
});

test('allows a minion with the same hash as the fighter', function () {
    $user = User::factory()->create();
    CustomCharacter::factory()->for($user)->create(['version_hash' => 'abc123def456']);

    $minion = CustomMinion::factory()->for($user)->create(['version_hash' => 'abc123def456']);

    expect($minion->exists)->toBeTrue()
        ->and($user->customCharacter->version_hash)->toBe('abc123def456')
        ->and($user->customMinions->pluck('version_hash')->all())->toBe(['abc123def456']);
});
