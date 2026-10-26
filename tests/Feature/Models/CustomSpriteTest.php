<?php

use App\Enums\CustomMinionSource;
use App\Enums\CustomSpriteState;
use App\Enums\CustomSpriteType;
use App\Filament\Resources\CustomCharacters\CustomCharacterResource;
use App\Filament\Resources\CustomMinions\CustomMinionResource;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\CustomCharacterVersion;
use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\CustomSprite;
use App\Models\User;
use App\Policies\CustomCharacterPolicy;
use App\Policies\CustomMinionPolicy;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

/**
 * One row of every kind for a player: the fighter in use, an earlier fighter version,
 * a fighter draft, a minion in slot 1 and a minion draft.
 *
 * @return User
 */
function userWithOneRowOfEachKind(): User
{
    $user = User::factory()->create();

    CustomCharacter::factory()->for($user)->create(['version_hash' => 'fighter00000']);
    CustomCharacterVersion::factory()->for($user)->create(['version_hash' => 'earlier00000']);
    CustomCharacterDraft::create(['user_id' => $user->id, 'version_hash' => 'fdraft000000', 'animations' => ['idle' => ['frames' => 6, 'rate' => 8]]]);
    CustomMinion::factory()->for($user)->create(['version_hash' => 'minion000000']);
    CustomMinionDraft::factory()->for($user)->create(['version_hash' => 'mdraft000000']);

    return $user;
}

test('the five sprite models share one abstract parent and keep their own names', function () {
    expect((new ReflectionClass(CustomSprite::class))->isAbstract())->toBeTrue();

    foreach ([CustomCharacter::class, CustomCharacterVersion::class, CustomCharacterDraft::class, CustomMinion::class, CustomMinionDraft::class] as $model) {
        expect(is_subclass_of($model, CustomSprite::class))->toBeTrue()
            ->and((new $model)->getTable())->toBe('custom_sprites');
    }
});

test('each model sees only the rows in its own slice of the table', function () {
    $user = userWithOneRowOfEachKind();

    expect(CustomCharacter::where('user_id', $user->id)->pluck('version_hash')->all())->toBe(['fighter00000'])
        ->and(CustomCharacterVersion::where('user_id', $user->id)->orderBy('id')->pluck('version_hash')->all())->toBe(['fighter00000', 'earlier00000'])
        ->and(CustomCharacterDraft::where('user_id', $user->id)->pluck('version_hash')->all())->toBe(['fdraft000000'])
        ->and(CustomMinion::where('user_id', $user->id)->pluck('version_hash')->all())->toBe(['minion000000'])
        ->and(CustomMinionDraft::where('user_id', $user->id)->pluck('version_hash')->all())->toBe(['mdraft000000']);
});

test('a new row is stamped as its own kind', function () {
    $user = userWithOneRowOfEachKind();

    $stamps = [
        [CustomCharacter::where('user_id', $user->id)->sole(), CustomSpriteType::Fighter, CustomSpriteState::Current],
        [CustomCharacterVersion::where('user_id', $user->id)->where('version_hash', 'earlier00000')->sole(), CustomSpriteType::Fighter, CustomSpriteState::Previous],
        [CustomCharacterDraft::where('user_id', $user->id)->sole(), CustomSpriteType::Fighter, CustomSpriteState::Draft],
        [CustomMinion::where('user_id', $user->id)->sole(), CustomSpriteType::Minion, CustomSpriteState::Current],
        [CustomMinionDraft::where('user_id', $user->id)->sole(), CustomSpriteType::Minion, CustomSpriteState::Draft],
    ];

    foreach ($stamps as [$row, $type, $state]) {
        expect($row->type)->toBe($type)
            ->and($row->state)->toBe($state)
            ->and($row->enabled)->toBeTrue();
    }
});

test('the local scopes pick rows by type and state across the whole table', function () {
    $user = userWithOneRowOfEachKind();
    $rows = fn () => CustomCharacter::withoutGlobalScopes()->where('user_id', $user->id);

    expect($rows()->fighter()->count())->toBe(3)
        ->and($rows()->minion()->count())->toBe(2)
        ->and($rows()->current()->count())->toBe(2)
        ->and($rows()->draft()->count())->toBe(2)
        ->and($rows()->previous()->count())->toBe(1)
        ->and($rows()->fighter()->current()->pluck('version_hash')->all())->toBe(['fighter00000'])
        ->and($rows()->minion()->draft()->pluck('version_hash')->all())->toBe(['mdraft000000']);
});

test('a local scope narrows the global scope of a model and never widens it', function () {
    $user = userWithOneRowOfEachKind();

    expect(CustomMinion::where('user_id', $user->id)->fighter()->count())->toBe(0)
        ->and(CustomMinion::where('user_id', $user->id)->draft()->count())->toBe(0)
        ->and(CustomCharacterVersion::where('user_id', $user->id)->current()->count())->toBe(1)
        ->and(CustomCharacterVersion::where('user_id', $user->id)->previous()->count())->toBe(1)
        ->and(CustomCharacterVersion::where('user_id', $user->id)->draft()->count())->toBe(0);
});

test('the shared accessors read and write the data column, keeping what else is in it', function () {
    $minion = CustomMinion::factory()->create(['slot' => 2, 'name' => 'Pip', 'body_px' => 31, 'source' => CustomMinionSource::Fighter]);

    expect($minion->name)->toBe('Pip')
        ->and($minion->body_px)->toBe(31)
        ->and($minion->source)->toBe(CustomMinionSource::Fighter)
        ->and($minion->animations)->toHaveKey('idle');

    $minion->name = 'Pop';
    $minion->animations = ['idle' => ['frames' => 2, 'rate' => 4]];
    $minion->source = 'upload';
    $minion->save();

    $fresh = $minion->fresh();

    expect($fresh->data)->toEqual([
        'name' => 'Pop',
        'animations' => ['idle' => ['frames' => 2, 'rate' => 4]],
        'body_px' => 31,
        'source' => 'upload',
    ])->and($fresh->source)->toBe(CustomMinionSource::Upload);
});

test('every model reads its fields from the same data column', function () {
    $user = userWithOneRowOfEachKind();

    expect(CustomCharacter::where('user_id', $user->id)->sole()->name)->toBe('Custom')
        ->and(CustomCharacterVersion::where('user_id', $user->id)->where('version_hash', 'earlier00000')->sole()->animations)->toHaveKey('walk')
        ->and(CustomCharacterDraft::where('user_id', $user->id)->sole()->animations)->toBe(['idle' => ['frames' => 6, 'rate' => 8]])
        ->and(CustomMinionDraft::where('user_id', $user->id)->sole()->name)->toBeNull()
        ->and(CustomMinionDraft::where('user_id', $user->id)->sole()->body_px)->toBe(22);
});

test('a model finds its own factory', function () {
    expect(CustomCharacter::factory()->make())->toBeInstanceOf(CustomCharacter::class)
        ->and(CustomCharacterVersion::factory()->make())->toBeInstanceOf(CustomCharacterVersion::class)
        ->and(CustomMinion::factory()->make())->toBeInstanceOf(CustomMinion::class)
        ->and(CustomMinionDraft::factory()->make())->toBeInstanceOf(CustomMinionDraft::class);
});

test('the fighter and the minion keep their own policy', function () {
    expect(Gate::getPolicyFor(CustomCharacter::class))->toBeInstanceOf(CustomCharacterPolicy::class)
        ->and(Gate::getPolicyFor(CustomMinion::class))->toBeInstanceOf(CustomMinionPolicy::class);
});

test('Shield still names the permissions after the fighter and the minion', function () {
    Filament::setCurrentPanel('admin');

    $groups = FilamentShield::transformResources();
    $character = $groups[CustomCharacterResource::class] ?? null;
    $minion = $groups[CustomMinionResource::class] ?? null;

    expect(CustomCharacterResource::getModel())->toBe(CustomCharacter::class)
        ->and(CustomMinionResource::getModel())->toBe(CustomMinion::class)
        ->and($character['model'] ?? null)->toBe('CustomCharacter')
        ->and($minion['model'] ?? null)->toBe('CustomMinion')
        ->and(collect($character['permissions'] ?? [])->pluck('key'))->toContain('ViewAny:CustomCharacter', 'Delete:CustomCharacter')
        ->and(collect($minion['permissions'] ?? [])->pluck('key'))->toContain('ViewAny:CustomMinion', 'Delete:CustomMinion');
});
