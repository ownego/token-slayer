<?php

use App\Events\FighterMinionsChanged;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionNoFighterException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\MinionPoolChangedException;
use App\Enums\MinionStageMode;
use App\Livewire\CustomMinionEditor;
use App\Models\Boss;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\ApplyMinionPool;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\MinionPoolSelection;
use App\Services\CustomCharacter\MinionStage;
use App\Services\CustomCharacter\RetargetCustomMinionDraft;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);
});

/**
 * A fake upload holding the given bytes.
 *
 * @param  string  $bytes  the file's contents
 * @param  string  $name  the client file name
 * @return UploadedFile
 */
function minionSheetUpload(string $bytes, string $name = 'minion.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * The minion editor for a user, as the fighter sheet mounts it.
 *
 * @param  User  $user  who is signed in
 * @return Livewire\Features\SupportTesting\Testable
 */
function minionEditorFor(User $user)
{
    return Livewire::actingAs($user)->test(CustomMinionEditor::class);
}

/**
 * Gives a player minions in slots 1..$count, named after their slot.
 *
 * @param  User  $user  the owner
 * @param  int  $count  how many
 * @return Illuminate\Database\Eloquent\Collection<int, CustomMinion>
 */
function minionsFor(User $user, int $count)
{
    return CustomMinion::factory()->count($count)->sequence(fn ($sequence) => ['slot' => $sequence->index + 1])->create(['user_id' => $user->id]);
}

/**
 * Makes the player's custom fighter, so there is one to copy.
 *
 * @param  User  $user  the owner
 * @return void
 */
function confirmedFighterFor(User $user): void
{
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
}

/**
 * The player's pool column, read straight from the database.
 *
 * @param  User  $user  the player
 * @return array{rev: int, defaults_off: list<string>, slots_off: list<int>}|null
 */
function minionPoolColumn(User $user): ?array
{
    $raw = DB::table('users')->where('id', $user->id)->value('minion_pool');

    return $raw === null ? null : json_decode($raw, true);
}

/**
 * The hash of each minion the player holds, by slot, as the tab draws them.
 *
 * @param  User  $user  the player
 * @return array<int, string>
 */
function minionHashes(User $user): array
{
    return CustomMinion::query()->where('user_id', $user->id)->orderBy('slot')->pluck('version_hash', 'slot')->all();
}

test('shows the five default looks and the three slots', function () {
    $editor = minionEditorFor(User::factory()->create())
        ->assertSee('Demon')
        ->assertSee('Blood monster')
        ->assertSee('Goomba')
        ->assertSee('Baby Bowser')
        ->assertSee('Bowser')
        ->assertSee('0 of 3')
        ->assertSee('Empty slot');

    $pool = $editor->viewData('pool');
    expect($pool['defaults'])->toHaveCount(5)
        ->and($pool['slots'])->toHaveCount(3)
        ->and(collect($pool['defaults'])->every(fn (array $cell): bool => $cell['ticked']))->toBeTrue();
});

test('shows a minion in its slot with its name and a tick', function () {
    $user = User::factory()->create();
    $minion = minionsFor($user, 1)->first();

    $editor = minionEditorFor($user)
        ->assertSee('1 of 3')
        ->assertSeeHtml("thumb-slot:1-{$minion->version_hash}")
        ->assertSeeHtml('aria-label="Minion 1 in the pool"');

    $pool = $editor->viewData('pool');
    expect($pool['slots'][0]['name'])->toBe('Minion 1')
        ->and($pool['slots'][0]['ticked'])->toBeTrue()
        ->and($pool['slots'][1]['idle'])->toBeNull();
});

test('ticking a look only changes the choice: nothing is stored, counted or announced', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    minionsFor($user, 2);

    $editor = minionEditorFor($user)
        ->call('toggleTick', 'default:demon-a', false)
        ->call('toggleTick', 'slot:2', false)
        ->assertHasNoErrors()
        ->assertNotDispatched('custom-minions-changed')
        ->assertNotDispatched('sheet-toast')
        ->assertSet('pending', ['defaults_off' => ['demon-a'], 'slots_off' => [2]])
        ->assertSee('Not saved yet.');

    $pool = $editor->viewData('pool');
    expect(collect($pool['defaults'])->firstWhere('key', 'demon-a')['ticked'])->toBeFalse()
        ->and($pool['slots'][1]['ticked'])->toBeFalse()
        ->and($editor->viewData('unsaved'))->toBeTrue()
        ->and(minionPoolColumn($user))->toBeNull()
        ->and(RateLimiter::attempts(CacheKeys::customMinionChange($user->id)))->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('ticking a look back to what is saved leaves nothing to save', function () {
    $user = User::factory()->create();

    $editor = minionEditorFor($user)
        ->call('toggleTick', 'default:demon-a', false)
        ->assertSet('pending', ['defaults_off' => ['demon-a'], 'slots_off' => []])
        ->call('toggleTick', 'default:demon-a', true)
        ->assertSet('pending', null)
        ->assertSee('Saved.');

    expect($editor->viewData('unsaved'))->toBeFalse();
});

test('Save stores the whole choice: one write, one revision step, one broadcast', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    minionsFor($user, 2);

    minionEditorFor($user)
        ->call('toggleTick', 'default:demon-a', false)
        ->call('toggleTick', 'default:smw-bowser', false)
        ->call('toggleTick', 'slot:2', false)
        ->call('save', minionHashes($user))
        ->assertHasNoErrors()
        ->assertSet('pending', null)
        ->assertDispatched('sheet-toast', message: 'Pool saved.')
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['changed'] === null
            && $params['pool']['rev'] === 1
            && $params['pool']['defaults'] === ['blood-monster-a', 'smw-goomba', 'smw-babybowser']
            && count($params['pool']['custom']) === 1)
        ->assertSee('Saved.');

    expect(minionPoolColumn($user))->toBe(['rev' => 1, 'defaults_off' => ['demon-a', 'smw-bowser'], 'slots_off' => [2]])
        ->and(CustomMinion::where('user_id', $user->id)->where('enabled', false)->count())->toBe(0);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('Save with a choice equal to the saved one writes nothing, bumps nothing and broadcasts nothing', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create(['minion_pool' => ['rev' => 3, 'defaults_off' => ['demon-a'], 'slots_off' => []]]);

    minionEditorFor($user)
        ->call('save', [])
        ->assertHasNoErrors()
        ->assertDispatched('sheet-toast', message: 'Pool already saved.');

    expect(minionPoolColumn($user)['rev'])->toBe(3);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('Save counts once against the per-minute allowance, and ticking does not', function () {
    config(['token_slayer.custom_character.minion_changes_per_minute' => 1]);
    $user = User::factory()->create();
    $editor = minionEditorFor($user);

    foreach (['demon-a', 'blood-monster-a', 'smw-goomba'] as $key) {
        $editor->call('toggleTick', "default:{$key}", false)->assertHasNoErrors();
    }

    $editor->call('save', [])->assertHasNoErrors();
    $editor->call('toggleTick', 'default:smw-bowser', false)
        ->call('save', [])
        ->assertHasErrors('action')
        ->assertSee('Try again in 1 minute.')
        ->assertDispatched('sheet-error');

    expect(minionPoolColumn($user)['rev'])->toBe(1);
});

test('Save from a tab that drew minions another tab has since changed is turned away and keeps the choice', function (string $what) {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $minions = minionsFor($user, 2);
    $drawn = minionHashes($user);

    $editor = minionEditorFor($user)->call('toggleTick', 'default:demon-a', false);

    match ($what) {
        'replaced' => $minions->last()->update(['version_hash' => 'f00dfeed1234']),
        'removed' => $minions->last()->delete(),
        'added' => CustomMinion::factory()->create(['user_id' => $user->id, 'slot' => 3]),
    };

    $editor->call('save', $drawn)
        ->assertHasErrors('action')
        ->assertDispatched('sheet-error', message: (new MinionPoolChangedException)->getMessage())
        ->assertSet('pending', ['defaults_off' => ['demon-a'], 'slots_off' => []]);

    expect(minionPoolColumn($user))->toBeNull();
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with(['replaced' => 'replaced', 'removed' => 'removed', 'added' => 'added']);

test('a Save sent with a forged hash is turned away', function () {
    $user = User::factory()->create();
    minionsFor($user, 1);

    minionEditorFor($user)
        ->call('save', [1 => 'forged'])
        ->assertHasErrors('action')
        ->call('save', [1 => ['nested']])
        ->assertHasErrors('action');

    expect(minionPoolColumn($user))->toBeNull();
});

test('a tab loaded before another tab saved still saves exactly the choice it sends, and the database, the revision and the broadcast agree', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $tabA = minionEditorFor($user->fresh());

    // tab B unticks Demon and saves
    app(ApplyMinionPool::class)->execute($user->fresh(), collect(config('token_slayer.custom_character.default_minions'))->mapWithKeys(fn (string $key): array => [$key => $key !== 'demon-a'])->all(), []);
    expect(minionPoolColumn($user))->toBe(['rev' => 1, 'defaults_off' => ['demon-a'], 'slots_off' => []]);

    // tab A, whose user model was loaded before that, ticks Demon back on and saves
    $tabA->call('toggleTick', 'default:demon-a', true)->call('save', [])->assertHasNoErrors();

    $stored = minionPoolColumn($user);
    expect($stored)->toBe(['rev' => 2, 'defaults_off' => [], 'slots_off' => []]);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 2);
    Event::assertDispatched(FighterMinionsChanged::class, fn (FighterMinionsChanged $event) => $event->user->minionPoolState()['rev'] === $stored['rev']
        && $event->user->minionPoolState()['defaults'] === config('token_slayer.custom_character.default_minions'));
});

test('a tab with no choice of its own neither undoes another tab\'s save nor bumps the revision', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $tabA = minionEditorFor($user->fresh());
    app(ApplyMinionPool::class)->execute($user->fresh(), collect(config('token_slayer.custom_character.default_minions'))->mapWithKeys(fn (string $key): array => [$key => $key !== 'demon-a'])->all(), []);

    // tab A saves with no choice of its own: it must not undo tab B's save, nor bump the revision for nothing
    $tabA->call('save', [])->assertHasNoErrors();

    expect(minionPoolColumn($user))->toBe(['rev' => 1, 'defaults_off' => ['demon-a'], 'slots_off' => []]);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('Reset drops the choice that is not saved', function () {
    $user = User::factory()->create(['minion_pool' => ['rev' => 2, 'defaults_off' => ['smw-bowser'], 'slots_off' => []]]);

    $editor = minionEditorFor($user)
        ->call('toggleTick', 'default:demon-a', false)
        ->assertSet('pending', ['defaults_off' => ['demon-a', 'smw-bowser'], 'slots_off' => []])
        ->assertSeeHtml('cme-reset-')
        ->call('resetPending')
        ->assertSet('pending', null)
        ->assertSee('Saved.')
        ->assertDontSeeHtml('cme-reset-');

    $pool = $editor->viewData('pool');
    expect(collect($pool['defaults'])->firstWhere('key', 'demon-a')['ticked'])->toBeTrue()
        ->and(collect($pool['defaults'])->firstWhere('key', 'smw-bowser')['ticked'])->toBeFalse();
});

test('a refresh from another tab puts the choice back to the saved one', function () {
    $user = User::factory()->create();

    minionEditorFor($user)
        ->call('toggleTick', 'default:demon-a', false)
        ->assertSet('pending', ['defaults_off' => ['demon-a'], 'slots_off' => []])
        ->dispatch('custom-minions-refresh')
        ->assertSet('pending', null);
});

test('the Save button is there, enabled only while there is a choice to save', function () {
    $user = User::factory()->create();

    $clean = minionEditorFor($user)->assertSeeHtml('cme-save-')->assertSee('Save pool');
    expect($clean->html())->toMatch('~id="cme-save-[^"]+"[^>]*\sdisabled~');

    $dirty = $clean->call('toggleTick', 'default:demon-a', false);
    expect($dirty->html())->not->toMatch('~id="cme-save-[^"]+"[^>]*\sdisabled~');
});

test('the note and the stage label follow the choice, and say when it is not saved', function () {
    $user = User::factory()->create();

    minionEditorFor($user)
        ->call('select', 'default:demon-a')
        ->assertSee('In the pool')
        ->call('toggleTick', 'default:demon-a', false)
        ->assertSee('Not in the pool')
        ->assertSee('not saved');
});

test('a forged look key changes nothing, keeps no choice and broadcasts nothing', function (string $look) {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $theirs = CustomMinion::factory()->create(['slot' => 2]);

    minionEditorFor($user)
        ->call('toggleTick', $look, false)
        ->assertHasErrors('action')
        ->assertSet('pending', null)
        ->assertDispatched('sheet-error');

    expect(minionPoolColumn($user))->toBeNull()
        ->and(minionPoolColumn($theirs->user))->toBeNull();
    Event::assertNotDispatched(FighterMinionsChanged::class);
})->with([
    'a default that does not exist' => 'default:evil',
    'a slot beyond the set' => 'slot:9',
    'a slot that holds somebody else\'s minion' => 'slot:2',
    'nonsense' => 'x',
]);

test('building into an empty slot previews it in that slot', function () {
    $user = User::factory()->create();
    minionsFor($user, 1);

    $editor = minionEditorFor($user)
        ->call('select', 'slot:2')
        ->assertSee('Upload sheet')
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasNoErrors()
        ->assertSet('selected', 'slot:2')
        ->assertSee('Add to minions')
        ->assertSee('Not in the pool until you add it.')
        ->assertDispatched('sheet-toast', message: 'Preview ready.')
        ->assertNotDispatched('custom-minions-changed');

    expect($user->fresh()->customMinionDraft->slot)->toBeNull()
        ->and($editor->viewData('pool')['slots'][1]['draft']['hash'])->toBe($user->fresh()->customMinionDraft->version_hash);
});

test('replacing builds a preview aimed at the minion\'s slot', function () {
    $user = User::factory()->create();
    minionsFor($user, 1);

    minionEditorFor($user)
        ->call('select', 'slot:1')
        ->call('startReplace', 1)
        ->assertSet('replacing', 1)
        ->assertSee('Replace Minion 1')
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasNoErrors()
        ->assertSet('replacing', null)
        ->assertSet('selected', 'slot:1')
        ->assertSee('Replace Minion 1');

    expect($user->fresh()->customMinionDraft->slot)->toBe(1);
});

test('starting to replace a slot the player does not hold is turned away', function () {
    minionEditorFor(User::factory()->create())
        ->call('startReplace', 2)
        ->assertSet('replacing', null)
        ->assertHasErrors('action');
});

test('adding a preview toasts the minion by name and lights its cell', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    minionsFor($user, 1);
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->call('confirm', $draft->version_hash, null)
        ->assertHasNoErrors()
        ->assertSet('selected', 'slot:2')
        ->assertSet('changed', 'slot:2')
        ->assertDispatched('sheet-toast', message: 'Added Minion 2')
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['changed'] === 'slot:2' && count($params['pool']['custom']) === 2)
        ->assertSeeHtml('is-lit');

    expect($user->customMinions()->count())->toBe(2);
});

test('replacing a minion toasts "Replaced" with its name and keeps its slot', function () {
    $user = User::factory()->create();
    $before = minionsFor($user, 1)->first();

    $editor = minionEditorFor($user)
        ->call('startReplace', 1)
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build');

    $editor->call('confirm', $user->fresh()->customMinionDraft->version_hash, 1)
        ->assertDispatched('sheet-toast', message: 'Replaced Minion 1')
        ->assertSet('selected', 'slot:1');

    $after = $user->customMinions()->where('slot', 1)->first();
    expect($after->version_hash)->not->toBe($before->version_hash)
        ->and($after->name)->toBe('Minion 1')
        ->and($user->customMinions()->count())->toBe(1);
});

test('asking again for a preview that is already in the set does not say it was added', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    $editor = minionEditorFor($user)->call('confirm', $draft->version_hash, null)->assertDispatched('sheet-toast', message: 'Added Minion 1');

    $editor->call('confirm', $draft->version_hash, null)
        ->assertHasNoErrors()
        ->assertDispatched('sheet-toast', message: (new CustomMinionDuplicateException)->getMessage())
        ->assertNotDispatched('custom-minions-changed')
        ->assertSet('changed', null);

    expect($user->customMinions()->count())->toBe(1);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('Use my fighter is locked with its reason while the player has no custom fighter', function () {
    $editor = minionEditorFor(User::factory()->create())
        ->call('select', 'slot:1')
        ->assertSee('Use my fighter')
        ->assertSeeHtml('data-minion-add-fighter="disabled"')
        ->assertSee('No custom fighter yet. Make one in the Fighter tab.');

    $editor->call('useFighter')
        ->assertDispatched('sheet-error', message: (new CustomMinionNoFighterException)->getMessage());
});

test('Use my fighter builds a preview first, and adds nothing until it is added', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    confirmedFighterFor($user);

    minionEditorFor($user->fresh())
        ->call('select', 'slot:1')
        ->assertSeeHtml('data-minion-add-fighter="ready"')
        ->call('useFighter')
        ->assertHasNoErrors()
        ->assertSet('selected', 'slot:1')
        ->assertSee('Add to minions')
        ->assertDispatched('sheet-toast', message: 'Preview ready.');

    expect($user->customMinions()->count())->toBe(0)
        ->and($user->fresh()->customMinionDraft->source->value)->toBe('fighter');
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('Use my fighter says so when that fighter is already a minion', function () {
    $user = User::factory()->create();
    confirmedFighterFor($user);
    $editor = minionEditorFor($user->fresh())->call('useFighter');
    $editor->call('confirm', $user->fresh()->customMinionDraft->version_hash, null);

    $editor->call('select', 'slot:2')
        ->assertSee((new CustomMinionDuplicateException)->getMessage())
        ->assertSeeHtml('data-minion-add-fighter="disabled"')
        ->call('useFighter')
        ->assertDispatched('sheet-error', message: (new CustomMinionDuplicateException)->getMessage());
});

test('Use my fighter on a full set with no minion to replace says the set is full', function () {
    $user = User::factory()->create();
    confirmedFighterFor($user);
    minionsFor($user, 3);

    minionEditorFor($user->fresh())
        ->call('useFighter')
        ->assertDispatched('sheet-error', message: (new CustomMinionSetFullException)->getMessage());

    expect($user->fresh()->customMinionDraft)->toBeNull();
});

test('the full-set sentence, pinned here once', function () {
    expect((new CustomMinionSetFullException)->getMessage())->toBe('Your minion set is full. Replace one to change it.');
});

test('a sheet over the size limit is turned away under the file box', function () {
    config(['token_slayer.custom_character.max_bytes' => 1024 * 1024]);

    minionEditorFor(User::factory()->create())
        ->set('sheet', UploadedFile::fake()->create('big.png', 2048, 'image/png'))
        ->call('build')
        ->assertHasErrors('sheet');
});

test('a file that is not a png, jpg or webp is turned away under the file box', function () {
    minionEditorFor(User::factory()->create())
        ->set('sheet', UploadedFile::fake()->create('sheet.gif', 10, 'image/gif'))
        ->call('build')
        ->assertHasErrors('sheet');
});

test('building with no file chosen asks for one', function () {
    minionEditorFor(User::factory()->create())->call('build')->assertHasErrors(['sheet' => 'required']);
});

test('shows a sheet problem with its hint', function () {
    $user = User::factory()->create();

    minionEditorFor($user)
        ->call('select', 'slot:1')
        ->set('sheet', minionSheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('The background must be solid magenta')
        ->assertSee('Ask the image AI for one flat magenta (#FF00FF) background');

    expect($user->customMinionDraft()->exists())->toBeFalse();
});

test('a sheet that builds a minion already in the set is refused by name', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    minionEditorFor($user)->call('confirm', $draft->version_hash, null);

    minionEditorFor($user->fresh())
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertDispatched('sheet-error', message: (new CustomMinionDuplicateException)->getMessage());
});

test('a second build while one is running is turned away with a message', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    minionEditorFor($user)
        ->call('select', 'slot:1')
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('still running');
});

test('building shares the hourly allowance with the fighter editor', function () {
    $user = User::factory()->create();
    $key = CacheKeys::customCharacterUpload($user->id);
    RateLimiter::clear($key);

    foreach (range(1, (int) config('token_slayer.custom_character.uploads_per_hour')) as $attempt) {
        RateLimiter::hit($key, 3600);
    }

    minionEditorFor($user)
        ->call('select', 'slot:1')
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('Too many uploads');

    expect($user->customMinionDraft()->exists())->toBeFalse();
});

test('a set that filled up under a preview gives it a cell of its own and lets the player aim it at a minion', function () {
    $user = User::factory()->create();
    minionsFor($user, 2);
    $draft = stagedMinionFor($user);
    CustomMinion::factory()->create(['user_id' => $user->id, 'slot' => 3]);

    $editor = minionEditorFor($user)
        ->assertSet('selected', 'overflow')
        ->assertSee('Your set filled up. Pick a minion to replace, or discard this preview.');

    expect($editor->viewData('pool')['overflowDraft']['hash'])->toBe($draft->version_hash);

    $editor->call('confirm', $draft->version_hash, null)
        ->assertDispatched('sheet-error', message: (new CustomMinionSetFullException)->getMessage());
    expect($user->customMinions()->count())->toBe(3);

    $editor->call('retarget', $draft->version_hash, 2)
        ->assertHasNoErrors()
        ->assertSet('selected', 'slot:2')
        ->assertSee('Replace Minion 2');

    expect($user->fresh()->customMinionDraft->slot)->toBe(2);
});

test('discarding a preview leaves the set as it was', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->call('discard', $draft->version_hash)
        ->assertHasNoErrors()
        ->assertDispatched('sheet-toast', message: 'Preview discarded.')
        ->assertNotDispatched('custom-minions-changed');

    expect($user->customMinionDraft()->exists())->toBeFalse()
        ->and($user->customMinions()->count())->toBe(0)
        ->and(minionPoolColumn($user))->toBeNull();
});

test('a stale tab\'s discard says the preview was replaced and keeps the newer draft', function () {
    $user = User::factory()->create();
    $seen = stagedMinionFor($user);
    $newer = stagedMinionFor($user, 1);

    minionEditorFor($user)
        ->call('discard', $seen->version_hash)
        ->assertDispatched('sheet-error', message: 'This preview was replaced. Build it again.')
        ->assertHasErrors('action');

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($newer->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$newer->version_hash}/idle.png");
});

test('removing asks first, in the right-hand panel, and then removes by name', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $minion = minionsFor($user, 1)->first();

    $editor = minionEditorFor($user)
        ->call('select', 'slot:1')
        ->call('askToConfirm', 'slot-1')
        ->assertSet('confirming', 'slot-1')
        ->assertSee('Remove Minion 1? Your subagents stop summoning it.')
        ->assertSeeHtml('wire:keydown.escape.stop.prevent="dismissConfirm"')
        ->assertSeeHtml('x-init="$el.focus()"')
        ->assertSeeHtml("removeMinion(1, '{$minion->version_hash}')")
        ->assertDontSeeHtml('wire:confirm');

    expect($user->customMinions()->count())->toBe(1);

    $editor->call('dismissConfirm')->assertSet('confirming', null)->assertDontSeeHtml('confirm-row');
    expect($user->customMinions()->count())->toBe(1);

    $editor->call('askToConfirm', 'slot-1')
        ->call('removeMinion', 1, $minion->version_hash)
        ->assertHasNoErrors()
        ->assertSet('confirming', null)
        ->assertSet('selected', 'slot:1')
        ->assertDispatched('sheet-toast', message: 'Minion 1 removed')
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['pool']['custom'] === []);

    expect($user->customMinions()->count())->toBe(0);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('a player cannot remove a slot they do not hold, or somebody else\'s minion', function () {
    $user = User::factory()->create();
    $theirs = CustomMinion::factory()->create(['slot' => 1]);

    minionEditorFor($user)
        ->call('removeMinion', 1, $theirs->version_hash)
        ->assertDispatched('sheet-error', message: 'That minion is not available.');

    expect(CustomMinion::find($theirs->id))->not->toBeNull();
});

test('removing a minion takes its slot out of the unsaved choice, so what is added there next is ticked', function () {
    $user = User::factory()->create();
    $minion = minionsFor($user, 1)->first();

    $editor = minionEditorFor($user)
        ->call('toggleTick', 'slot:1', false)
        ->assertSet('pending', ['defaults_off' => [], 'slots_off' => [1]])
        ->call('removeMinion', 1, $minion->version_hash)
        ->assertSet('pending', ['defaults_off' => [], 'slots_off' => []]);

    expect($editor->viewData('unsaved'))->toBeFalse();
});

test('removing a cell another tab has just replaced is refused, and the new minion stays', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $shown = minionsFor($user, 1)->first();
    $shownHash = $shown->version_hash;
    $editor = minionEditorFor($user)->call('select', 'slot:1');

    // another tab replaces slot 1 with a different look
    $shown->update(['version_hash' => 'f00dfeed1234']);

    $editor->call('removeMinion', 1, $shownHash)
        ->assertHasErrors('action')
        ->assertDispatched('sheet-error', message: (new MinionPoolChangedException)->getMessage())
        ->assertNotDispatched('sheet-toast');

    expect($user->customMinions()->count())->toBe(1)
        ->and(minionPoolColumn($user))->toBeNull();
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('removing a minion that is already gone says nothing was removed', function () {
    $user = User::factory()->create();
    $shown = minionsFor($user, 1)->first();
    $editor = minionEditorFor($user)->call('select', 'slot:1');
    $service = Mockery::mock(\App\Services\CustomCharacter\RemoveCustomMinion::class);
    $service->shouldReceive('execute')->once()->andReturn(false);
    app()->instance(\App\Services\CustomCharacter\RemoveCustomMinion::class, $service);

    $editor->call('removeMinion', 1, $shown->version_hash)
        ->assertHasNoErrors()
        ->assertSet('selected', 'slot:1')
        ->assertNotDispatched('sheet-toast');
});

test('a busy lock on remove shows the busy message and keeps the minion', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    minionsFor($user, 1);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    minionEditorFor($user)
        ->call('removeMinion', 1, CustomMinion::where('user_id', $user->id)->value('version_hash'))
        ->assertHasErrors('action')
        ->assertDispatched('sheet-error');

    expect($user->customMinions()->count())->toBe(1);
});

test('what is pending a confirm cannot be set from the browser', function () {
    expect(fn () => minionEditorFor(User::factory()->create())->set('confirming', 'slot-1'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('what is selected, replaced, lit or chosen cannot be set from the browser either', function (string $property, mixed $value) {
    expect(fn () => minionEditorFor(User::factory()->create())->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'selected' => ['selected', 'slot:1'],
    'replacing' => ['replacing', 1],
    'changed' => ['changed', 'slot:1'],
    'pending' => ['pending', ['defaults_off' => ['demon-a'], 'slots_off' => []]],
]);

test('a confirm token that is not a held slot is forgotten, and none of it reaches a script', function () {
    minionEditorFor(User::factory()->create())
        ->call('askToConfirm', 'slot-1"); alert(1);//')
        ->assertSet('confirming', null)
        ->call('askToConfirm', '../etc')
        ->assertSet('confirming', null);
});

test('changes to the pool are rate limited per minute, in words that fit the wait', function () {
    config(['token_slayer.custom_character.minion_changes_per_minute' => 1]);
    $user = User::factory()->create();

    $editor = minionEditorFor($user)->call('toggleTick', 'default:demon-a', false)->call('save', [])->assertHasNoErrors();

    $editor->call('toggleTick', 'default:demon-a', true)
        ->call('save', [])
        ->assertHasErrors('action')
        ->assertSee('Try again in 1 minute.')
        ->assertDontSee('1 minutes')
        ->assertDispatched('sheet-error');

    expect(minionPoolColumn($user)['defaults_off'])->toBe(['demon-a']);
});

test('adding, discarding and removing are held to the same per-minute allowance', function (string $action) {
    config(['token_slayer.custom_character.minion_changes_per_minute' => 1]);
    $user = User::factory()->create();
    $minion = minionsFor($user, 1)->first();
    $draft = stagedMinionFor($user, 1);
    $key = CacheKeys::customMinionChange($user->id);
    RateLimiter::clear($key);
    RateLimiter::hit($key, 60);

    $editor = minionEditorFor($user);
    match ($action) {
        'confirm' => $editor->call('confirm', $draft->version_hash, null),
        'discard' => $editor->call('discard', $draft->version_hash),
        'remove' => $editor->call('removeMinion', 1, $minion->version_hash),
    };

    $editor->assertHasErrors('action')->assertSee('Try again in 1 minute.');
    expect($user->customMinions()->count())->toBe(1)
        ->and($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash)
        ->and(minionPoolColumn($user))->toBeNull();
})->with(['confirm' => 'confirm', 'discard' => 'discard', 'remove' => 'remove']);

test('a tick and a Save cost the same number of queries however many minions there are', function () {
    $measure = function (User $user): array {
        $editor = minionEditorFor($user);

        DB::enableQueryLog();
        DB::flushQueryLog();
        $editor->call('toggleTick', 'default:demon-a', false);
        $tick = count(DB::getQueryLog());
        DB::flushQueryLog();
        $editor->call('save', minionHashes($user));
        $save = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$tick, $save];
    };

    $few = User::factory()->create();
    minionsFor($few, 1);
    $many = User::factory()->create();
    minionsFor($many, 3);

    // a query per cell (8 cells) creeping back in (#15) would make the second count larger
    expect($measure($many))->toBe($measure($few));
});

test('re-renders on a refresh so an admin removal makes the minion go', function () {
    $user = User::factory()->create();
    $minion = minionsFor($user, 1)->first();

    $editor = minionEditorFor($user)->assertSeeHtml("thumb-slot:1-{$minion->version_hash}");

    $minion->delete();

    $editor->dispatch('custom-minions-refresh')
        ->assertDontSeeHtml("thumb-slot:1-{$minion->version_hash}")
        ->assertSee('0 of 3');
});

test('opens on the cell that holds a preview', function () {
    $user = User::factory()->create();
    stagedMinionFor($user);

    minionEditorFor($user)->assertSet('selected', 'slot:1')->assertSee('Add to minions');
});

test('nothing ticked says the swarm falls back to the defaults', function () {
    $user = User::factory()->create();
    $user->forceFill(['minion_pool' => ['rev' => 1, 'defaults_off' => ['demon-a', 'blood-monster-a', 'smw-goomba', 'smw-babybowser', 'smw-bowser'], 'slots_off' => []]])->save();

    minionEditorFor($user->fresh())->assertSee('Nothing ticked: subagents summon the defaults.')->assertDontSee('Not saved yet.');
});

test('the nothing-ticked note follows the choice and says it is not saved', function () {
    $user = User::factory()->create();
    $editor = minionEditorFor($user)->assertDontSee('Nothing ticked');

    foreach (config('token_slayer.custom_character.default_minions') as $key) {
        $editor->call('toggleTick', "default:{$key}", false);
    }

    $editor->assertSee('Nothing ticked: subagents summon the defaults.')->assertSee('Not saved yet.');
    expect(minionPoolColumn($user))->toBeNull();
});

test('the stage is a value object the view only reads', function () {
    $user = User::factory()->create();
    minionsFor($user, 1);

    $editor = minionEditorFor($user)->call('select', 'slot:1');
    $stage = $editor->viewData('stage');

    expect($stage)->toBeInstanceOf(MinionStage::class)
        ->and($stage->mode)->toBe(MinionStageMode::Minion)
        ->and($stage->slot)->toBe(1);
    $editor->call('select', 'slot:2');
    expect($editor->viewData('stage')->mode)->toBe(MinionStageMode::Empty);
    $editor->call('select', 'default:smw-goomba');
    expect($editor->viewData('stage')->mode)->toBe(MinionStageMode::Default);
});

test('the stage carries the move hotbar the stage script fills in', function () {
    minionEditorFor(User::factory()->create())
        ->assertSeeHtml('class="ms-moves"')
        ->assertSeeHtml('role="toolbar"')
        ->assertSeeHtml('x-for="m in moves"')
        ->assertSeeHtml('x-on:click="play(m.id)"')
        ->assertSeeHtml("x-bind:aria-pressed=\"move === m.id ? 'true' : 'false'\"")
        ->assertSee('Demon moves');
});



test('confirming a preview another tab re-aimed at a different slot is refused, and nothing is replaced', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    confirmedFighterFor($user);
    $other = minionsFor($user, 2);
    $editor = minionEditorFor($user->fresh())->call('startReplace', 1)->call('useFighter');
    $hash = $user->fresh()->customMinionDraft->version_hash;
    $slot = $user->fresh()->customMinionDraft->slot;
    expect($slot)->toBe(1);

    // another tab aims the very same preview at slot 2
    app(RetargetCustomMinionDraft::class)->execute($user->fresh(), $hash, 2);

    $editor->call('confirm', $hash, $slot)
        ->assertHasErrors('action');

    expect($user->customMinions()->count())->toBe(2)
        ->and($user->customMinions()->where('slot', 2)->value('version_hash'))->toBe($other->firstWhere('slot', 2)->version_hash)
        ->and($user->fresh()->customMinionDraft->slot)->toBe(2);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('the stage hands the shared roster the fighter with its name and author, not the bare broadcast state', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    $user->customCharacter()->first()->update(['name' => 'Hai Ninja']);

    $custom = minionEditorFor($user->fresh())->viewData('fieldFighter')['custom'];

    expect($custom['name'])->toBe('Hai Ninja')
        ->and($custom)->toHaveKey('author');
});

test('the stage gets no fighter while the custom one is switched off', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    $user->customCharacter()->first()->update(['active' => false]);

    expect(minionEditorFor($user->fresh())->viewData('fieldFighter')['custom'])->toBeNull();
});
