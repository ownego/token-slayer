<?php

use App\Events\FighterMinionsChanged;
use App\Livewire\CustomMinionEditor;
use App\Models\Boss;
use App\Models\CustomMinion;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Support\CacheKeys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
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

test('builds a draft and announces it', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();

    minionEditorFor($user)
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasNoErrors()
        ->assertDispatched('custom-minions-changed', function (string $event, array $params) use ($user): bool {
            return $params['minions'] === []
                && $params['draft']['hash'] === $user->fresh()->customMinionDraft->version_hash
                && $params['draft']['spec']['url_base'] !== ''
                && isset($params['draft']['spec']['body_px']);
        })
        ->assertDispatched('sheet-toast');

    expect($user->customMinions()->count())->toBe(0);
    Event::assertNotDispatched(FighterMinionsChanged::class);
});

test('a successful build says its own file is done', function () {
    minionEditorFor(User::factory()->create())
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertDispatched('custom-minion-built');
});

test('a failed build does not say its file is done', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    stagedMinionFor($user);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    minionEditorFor($user)
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertDispatched('custom-minions-changed')
        ->assertNotDispatched('custom-minion-built');
});

test('a set change that is not a build does not say the chosen file is done', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    $editor = minionEditorFor($user->fresh());

    $editor->call('addFighter')
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['draft'] !== null)
        ->assertNotDispatched('custom-minion-built');

    $editor->call('discard', $draft->version_hash)->assertNotDispatched('custom-minion-built');
});

test('adding the previewed draft does not say the chosen file is done', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->call('confirm', $draft->version_hash)
        ->assertNotDispatched('custom-minion-built');
});

test('the form clears its chosen file only when its own build is done', function () {
    minionEditorFor(User::factory()->create())
        ->assertSeeHtml('x-on:custom-minion-built.window="phase = \'done\'; clearThumb()"')
        ->assertSeeHtml('x-on:custom-minions-changed.window="if (phase === \'done\' && ! $event.detail?.draft) { phase = \'idle\' }"');
});

test('adds the draft to the set', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->call('confirm', $draft->version_hash)
        ->assertHasNoErrors()
        ->assertDispatched('custom-minions-changed', function (string $event, array $params) use ($draft): bool {
            return count($params['minions']) === 1
                && $params['minions'][0]['version'] === $draft->version_hash
                && $params['minions'][0]['source'] === 'upload'
                && $params['draft'] === null;
        })
        ->assertDispatched('sheet-toast', message: 'Added to your minions');

    expect($user->customMinions()->count())->toBe(1);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('shows the empty-set line', function () {
    minionEditorFor(User::factory()->create())
        ->assertSee('Your subagents summon the default minions. Add up to 3 of your own.')
        ->assertSee('Add minion')
        ->assertSeeHtml('wire:model="sheet"');
});

test('shows a card per minion with its preview and remove buttons', function () {
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->create(['user_id' => $user->id]);

    minionEditorFor($user)
        ->assertDontSee('Your subagents summon the default minions.')
        ->assertSeeHtml('class="vsprite"')
        ->assertSeeHtml("/custom-minions/{$user->id}/{$minion->version_hash}/idle.png")
        ->assertSeeHtml('custom-minion-peek')
        ->assertSee('Preview')
        ->assertSee('Remove');
});

test('shows the full state and disables adding', function () {
    $user = User::factory()->create();
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);

    minionEditorFor($user)
        ->assertSee('3 of 3. Remove one to add another.')
        ->assertSeeHtml('data-minion-add="disabled"')
        ->assertDontSeeHtml('wire:model="sheet"');
});

test('offers Add my fighter only with a confirmed fighter', function () {
    $user = User::factory()->create();

    minionEditorFor($user)->assertDontSee('Add my fighter');

    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    minionEditorFor($user->fresh())
        ->assertSee('Add my fighter')
        ->assertSeeHtml('data-minion-add-fighter="ready"');
});

test('adds the fighter as a minion', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $fighter = app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    minionEditorFor($user)
        ->call('addFighter')
        ->assertHasNoErrors()
        ->assertDispatched('custom-minions-changed', function (string $event, array $params) use ($fighter): bool {
            return count($params['minions']) === 1
                && $params['minions'][0]['version'] === $fighter->version_hash
                && $params['minions'][0]['source'] === 'fighter';
        })
        ->assertDispatched('sheet-toast', message: 'Added to your minions');
});

test('disables Add my fighter when that fighter is already a minion', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);

    minionEditorFor($user)
        ->call('addFighter')
        ->assertSee('Already in your minions.')
        ->assertSeeHtml('data-minion-add-fighter="disabled"');
});

test('disables Add my fighter with the full reason when the set is full', function () {
    $user = User::factory()->create();
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user)->version_hash);
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);

    minionEditorFor($user->fresh())
        ->assertSeeHtml('data-minion-add-fighter="disabled"')
        ->assertSee('3 of 3. Remove one to add another.');
});

test('asks before removing and removes on confirm', function () {
    Event::fake([FighterMinionsChanged::class]);
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->create(['user_id' => $user->id]);

    $editor = minionEditorFor($user)
        ->call('askToConfirm', "minion-{$minion->id}")
        ->assertSeeHtml('confirm-row')
        ->assertDontSeeHtml('wire:confirm');

    expect($user->customMinions()->count())->toBe(1);

    $editor->call('dismissConfirm')->assertDontSeeHtml('confirm-row');
    expect($user->customMinions()->count())->toBe(1);

    $editor->call('askToConfirm', "minion-{$minion->id}")
        ->call('removeMinion', $minion->id)
        ->assertHasNoErrors()
        ->assertSet('confirming', null)
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['minions'] === [])
        ->assertDispatched('sheet-toast', message: 'Minion removed');

    expect($user->customMinions()->count())->toBe(0);
    Event::assertDispatchedTimes(FighterMinionsChanged::class, 1);
});

test('cannot remove another player\'s minion', function () {
    $user = User::factory()->create();
    $theirs = CustomMinion::factory()->create();

    minionEditorFor($user)
        ->call('removeMinion', $theirs->id)
        ->assertDispatched('sheet-error', message: 'That minion is not available.');

    expect(CustomMinion::find($theirs->id))->not->toBeNull();
});

test('shares the upload limit with the fighter editor', function () {
    $user = User::factory()->create();
    $key = "custom-character-upload:{$user->id}";
    RateLimiter::clear($key);

    foreach (range(1, (int) config('token_slayer.custom_character.uploads_per_hour')) as $attempt) {
        RateLimiter::hit($key, 3600);
    }

    minionEditorFor($user)
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('Too many uploads');

    expect($user->customMinionDraft()->exists())->toBeFalse();
});

test('shows a sheet problem with its hint', function () {
    $user = User::factory()->create();

    minionEditorFor($user)
        ->set('sheet', minionSheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('The background must be solid magenta')
        ->assertSee('Ask the image AI for one flat magenta (#FF00FF) background');

    expect($user->customMinionDraft()->exists())->toBeFalse();
});

test('a second build while one is running is turned away with a message', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    minionEditorFor($user)
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('still running');
});

test('refuses a sheet that builds a minion already in the set', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    minionEditorFor($user)->call('confirm', $draft->version_hash);

    minionEditorFor($user->fresh())
        ->set('sheet', minionSheetUpload(pngBytes(variantPoseSheet(0))))
        ->call('build')
        ->assertDispatched('sheet-error', message: 'Already in your minions.');
});

test('discards the draft and tells the stage', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->call('discard', $draft->version_hash)
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['draft'] === null)
        ->assertDispatched('sheet-toast');

    expect($user->customMinionDraft()->exists())->toBeFalse();
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

test('the Discard button names the draft it shows', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)->assertSeeHtml("wire:click=\"discard('{$draft->version_hash}')\"");
});

test('the stage\'s confirm always gets an answer: an add, a no-op or an error', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    $editor = minionEditorFor($user);
    $editor->dispatch('custom-minion-draft-confirm', hash: $draft->version_hash)
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => count($params['minions']) === 1);

    // the same hash again (a double click, or another tab): nothing new, but still answered
    $editor->dispatch('custom-minion-draft-confirm', hash: $draft->version_hash)
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => count($params['minions']) === 1);
    expect($user->customMinions()->count())->toBe(1);

    // a preview that was replaced
    $editor->dispatch('custom-minion-draft-confirm', hash: 'aaaaaaaaaaaa')
        ->assertDispatched('sheet-error', message: 'This preview was replaced. Build it again.');
});

test('the stage\'s confirm on a full set is answered with the full message', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);
    CustomMinion::factory()->count(3)->create(['user_id' => $user->id]);

    minionEditorFor($user)
        ->dispatch('custom-minion-draft-confirm', hash: $draft->version_hash)
        ->assertDispatched('sheet-error', message: '3 of 3. Remove one to add another.');

    expect($user->fresh()->customMinionDraft)->not->toBeNull();
});

test('the stage\'s discard drops the draft it previewed', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    minionEditorFor($user)
        ->dispatch('custom-minion-draft-discard', hash: $draft->version_hash)
        ->assertDispatched('custom-minions-changed', fn (string $event, array $params): bool => $params['draft'] === null);

    expect($user->customMinionDraft()->exists())->toBeFalse();
});

test('the stage\'s discard of a replaced preview is answered with an error and keeps the newer draft', function () {
    $user = User::factory()->create();
    $seen = stagedMinionFor($user);
    $newer = stagedMinionFor($user, 1);

    minionEditorFor($user)
        ->dispatch('custom-minion-draft-discard', hash: $seen->version_hash)
        ->assertDispatched('sheet-error', message: 'This preview was replaced. Build it again.');

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($newer->version_hash);
});

test('an old stage event that carries no hash removes no draft', function () {
    $user = User::factory()->create();
    $draft = stagedMinionFor($user);

    try {
        minionEditorFor($user)->dispatch('custom-minion-draft-discard');
    } catch (Throwable) {
        // an event without the hash is refused outright
    }

    expect($user->fresh()->customMinionDraft->version_hash)->toBe($draft->version_hash);
    Storage::disk('public')->assertExists("custom-minions/{$user->id}/staged/{$draft->version_hash}/idle.png");
});

test('re-renders on a refresh so an admin removal makes the card go', function () {
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->create(['user_id' => $user->id]);

    $editor = minionEditorFor($user)->assertSeeHtml("{$minion->version_hash}/idle.png");

    $minion->delete();

    $editor->dispatch('custom-minions-refresh')
        ->assertDontSeeHtml("{$minion->version_hash}/idle.png")
        ->assertSee('Your subagents summon the default minions.');
});

test('a busy lock on remove shows the busy message', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $minion = CustomMinion::factory()->create(['user_id' => $user->id]);
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    minionEditorFor($user)
        ->call('removeMinion', $minion->id)
        ->assertHasErrors('action')
        ->assertDispatched('sheet-error');

    expect(CustomMinion::find($minion->id))->not->toBeNull();
});
