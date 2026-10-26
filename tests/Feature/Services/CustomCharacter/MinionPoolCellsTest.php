<?php

use App\Enums\MinionStageMode;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\MinionPoolCells;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\MinionStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
});

/**
 * The player with what the cells are built from loaded, as the editor loads them.
 *
 * @param  User  $user  the player
 * @return User
 */
function cellsUser(User $user): User
{
    return $user->fresh(['customMinions', 'customMinionDraft']);
}

/**
 * Gives a player minions in slots 1..$count.
 *
 * @param  User  $user  the owner
 * @param  int  $count  how many
 * @return void
 */
function cellsMinions(User $user, int $count): void
{
    CustomMinion::factory()->count($count)->sequence(fn ($sequence) => ['slot' => $sequence->index + 1])->create(['user_id' => $user->id]);
}

describe('the cells', function () {
    it('draws a tick for every look from the stored selection', function () {
        $user = User::factory()->create(['minion_pool' => ['rev' => 2, 'defaults_off' => ['demon-a'], 'slots_off' => [2]]]);
        cellsMinions($user, 2);

        $pool = app(MinionPoolCells::class)->get(cellsUser($user));

        expect(collect($pool['defaults'])->pluck('ticked', 'key')->all())->toBe([
            'demon-a' => false,
            'blood-monster-a' => true,
            'smw-goomba' => true,
            'smw-babybowser' => true,
            'smw-bowser' => true,
        ])->and(collect($pool['slots'])->pluck('ticked')->all())->toBe([true, false, false])
            ->and($pool['nothingTicked'])->toBeFalse();
    });

    it('draws the selection it is given instead of the stored one', function () {
        $user = User::factory()->create();
        cellsMinions($user, 1);
        $chosen = MinionPoolSelection::of($user)->withDefault('smw-bowser', false)->withSlot(1, false);

        $pool = app(MinionPoolCells::class)->get(cellsUser($user), $chosen);

        expect(collect($pool['defaults'])->firstWhere('key', 'smw-bowser')['ticked'])->toBeFalse()
            ->and($pool['slots'][0]['ticked'])->toBeFalse();
    });

    it('never draws an empty slot as ticked', function () {
        $pool = app(MinionPoolCells::class)->get(cellsUser(User::factory()->create()));

        expect(collect($pool['slots'])->pluck('ticked')->unique()->all())->toBe([false]);
    });

    it('says nothing is ticked only when no default and no minion is in the pool', function () {
        $user = User::factory()->create(['minion_pool' => ['rev' => 1, 'defaults_off' => config('token_slayer.custom_character.default_minions'), 'slots_off' => [1]]]);
        cellsMinions($user, 2);
        $cells = app(MinionPoolCells::class);

        expect($cells->get(cellsUser($user))['nothingTicked'])->toBeFalse()
            ->and($cells->get(cellsUser($user), MinionPoolSelection::of($user)->withSlot(2, false))['nothingTicked'])->toBeTrue();
    });

    it('hands the view the hash of every minion it draws, by slot', function () {
        $user = User::factory()->create();
        $one = CustomMinion::factory()->for($user)->create(['slot' => 1]);
        $three = CustomMinion::factory()->for($user)->create(['slot' => 3]);

        $pool = app(MinionPoolCells::class)->get(cellsUser($user));

        expect($pool['hashes'])->toBe([1 => $one->version_hash, 3 => $three->version_hash]);
    });

    it('does not read the minion row\'s enabled flag', function () {
        $user = User::factory()->create();
        CustomMinion::factory()->for($user)->create(['slot' => 1, 'enabled' => false]);

        expect(app(MinionPoolCells::class)->get(cellsUser($user))['slots'][0]['ticked'])->toBeTrue();
    });
});

describe('the cell on the stage', function () {
    it('keeps the selected cell when it exists, else falls back to the preview, else the first default', function () {
        $user = User::factory()->create();
        cellsMinions($user, 1);
        $cells = app(MinionPoolCells::class);
        $pool = $cells->get(cellsUser($user));

        expect($cells->resolveSelected($pool, 'slot:1'))->toBe('slot:1')
            ->and($cells->resolveSelected($pool, 'default:smw-goomba'))->toBe('default:smw-goomba')
            ->and($cells->resolveSelected($pool, ''))->toBe('default:demon-a')
            ->and($cells->resolveSelected($pool, 'slot:9'))->toBe('default:demon-a')
            ->and($cells->resolveSelected($pool, 'overflow'))->toBe('default:demon-a');

        stagedMinionFor($user);
        $withPreview = $cells->get(cellsUser($user));

        expect($cells->resolveSelected($withPreview, ''))->toBe('slot:2')
            ->and($cells->resolveSelected($withPreview, 'slot:1'))->toBe('slot:1')
            ->and($cells->resolveSelected($withPreview, 'slot:1', preferPreview: true))->toBe('slot:2');
    });

    it('falls back to the overflow preview when the set filled up under it', function () {
        $user = User::factory()->create();
        cellsMinions($user, 2);
        stagedMinionFor($user);
        CustomMinion::factory()->for($user)->create(['slot' => 3]);
        $cells = app(MinionPoolCells::class);
        $pool = $cells->get(cellsUser($user));

        expect($cells->resolveSelected($pool, ''))->toBe('overflow')
            ->and($cells->resolveSelected($pool, 'overflow'))->toBe('overflow');
    });

    it('describes a default look', function () {
        $user = User::factory()->create(['minion_pool' => ['rev' => 1, 'defaults_off' => ['smw-goomba'], 'slots_off' => []]]);
        $cells = app(MinionPoolCells::class);

        $stage = $cells->stageOf($cells->get(cellsUser($user)), 'default:smw-goomba');

        expect($stage)->toBeInstanceOf(MinionStage::class)
            ->and($stage->mode)->toBe(MinionStageMode::Default)
            ->and($stage->look)->toBe('default:smw-goomba')
            ->and($stage->name)->toBe('Goomba')
            ->and($stage->draft)->toBeNull()
            ->and($stage->spec)->toBe(['kind' => 'default', 'key' => 'smw-goomba'])
            ->and($stage->ticked)->toBeFalse()
            ->and($stage->slot)->toBeNull()
            ->and($stage->isTickable())->toBeTrue()
            ->and($stage->isPreview())->toBeFalse();
    });

    it('describes one of the player\'s minions', function () {
        $user = User::factory()->create(['minion_pool' => ['rev' => 1, 'defaults_off' => [], 'slots_off' => [1]]]);
        $minion = CustomMinion::factory()->for($user)->create(['slot' => 1, 'name' => 'Biter']);
        $cells = app(MinionPoolCells::class);

        $stage = $cells->stageOf($cells->get(cellsUser($user)), 'slot:1');

        expect($stage->mode)->toBe(MinionStageMode::Minion)
            ->and($stage->name)->toBe('Biter')
            ->and($stage->spec['kind'])->toBe('custom')
            ->and($stage->spec['version'])->toBe($minion->version_hash)
            ->and($stage->ticked)->toBeFalse()
            ->and($stage->slot)->toBe(1)
            ->and($stage->draft)->toBeNull()
            ->and($stage->isTickable())->toBeTrue();
    });

    it('describes an empty slot', function () {
        $cells = app(MinionPoolCells::class);

        $stage = $cells->stageOf($cells->get(cellsUser(User::factory()->create())), 'slot:2');

        expect($stage->mode)->toBe(MinionStageMode::Empty)
            ->and($stage->name)->toBeNull()
            ->and($stage->spec)->toBeNull()
            ->and($stage->ticked)->toBeFalse()
            ->and($stage->slot)->toBe(2)
            ->and($stage->isTickable())->toBeFalse();
    });

    it('describes a preview waiting in a slot', function () {
        $user = User::factory()->create();
        cellsMinions($user, 1);
        $draft = stagedMinionFor($user);
        $cells = app(MinionPoolCells::class);

        $stage = $cells->stageOf($cells->get(cellsUser($user)), 'slot:2');

        expect($stage->mode)->toBe(MinionStageMode::Draft)
            ->and($stage->name)->toBe('Minion 2')
            ->and($stage->draft['hash'])->toBe($draft->version_hash)
            ->and($stage->spec)->toBe($stage->draft['spec'])
            ->and($stage->slot)->toBe(2)
            ->and($stage->isPreview())->toBeTrue()
            ->and($stage->isTickable())->toBeFalse();
    });

    it('describes the preview that has no slot to sit in', function () {
        $user = User::factory()->create();
        cellsMinions($user, 2);
        $draft = stagedMinionFor($user);
        CustomMinion::factory()->for($user)->create(['slot' => 3]);
        $cells = app(MinionPoolCells::class);

        $stage = $cells->stageOf($cells->get(cellsUser($user)), 'overflow');

        expect($stage->mode)->toBe(MinionStageMode::Overflow)
            ->and($stage->look)->toBe('overflow')
            ->and($stage->draft['hash'])->toBe($draft->version_hash)
            ->and($stage->slot)->toBeNull()
            ->and($stage->ticked)->toBeFalse()
            ->and($stage->isPreview())->toBeTrue();
    });

    it('has exactly the five modes the view branches on', function () {
        expect(collect(MinionStageMode::cases())->map->value->all())->toBe(['overflow', 'default', 'draft', 'empty', 'minion']);
    });
});
