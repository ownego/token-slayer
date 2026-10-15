<?php

use App\Events\FighterCharacterChanged;
use App\Livewire\CustomCharacterEditor;
use App\Models\Boss;
use App\Models\CustomCharacter;
use App\Models\CustomCharacterDraft;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\StageCustomCharacter;
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
    RateLimiter::clear('custom-character-upload:1');
});

/**
 * A fake upload holding the given bytes.
 *
 * @param  string  $bytes  the file's contents
 * @param  string  $name  the client file name
 * @return UploadedFile
 */
function sheetUpload(string $bytes, string $name = 'sheet.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

/**
 * The editor for a user, as the fighter sheet mounts it.
 *
 * @param  User  $user  who is signed in
 * @return Livewire\Features\SupportTesting\Testable
 */
function editorFor(User $user)
{
    return Livewire::actingAs($user)->test(CustomCharacterEditor::class);
}

test('the page sends guests to the slack login', function () {
    $this->get('/character')->assertRedirect();
});

test('the page is the guide only and links to the editor in the same tab', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $html = $this->get('/character')->assertOk()
        ->assertSee('1. Ask an image AI for a pose sheet')
        ->assertSee('2. Upload it in the game')
        ->assertDontSeeLivewire(CustomCharacterEditor::class)
        ->getContent();

    expect($html)->toContain('href="'.route('battlefield').'?sheet=character&amp;editor=1"')
        ->not->toContain('target="_blank"');
});

test('the battlefield opens the sheet on the Character tab when asked to by the guide\'s link', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/battlefield')->assertOk()->assertSee("get('sheet') === 'character'", false);
});

test('the guide says to pick a costume by character type, then copy the prompt into ChatGPT with a picture of the character', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertSee('Pick a costume by character type below')
        ->assertSee('copy its prompt and paste it into ChatGPT')
        ->assertSee('together with a picture of your custom character')
        ->assertSee('to make your pose sheet');
});

test('the page lets the user pick a character type before it offers a prompt', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $response = $this->get('/character')->assertOk()->assertSee('Choose a character type to get its prompt.');

    foreach (['Warrior', 'Wizard', 'Assassin', 'Archer', 'Healer', 'Necromancer', 'Knight', 'Alchemist', 'Berserker'] as $label) {
        $response->assertSee($label);
    }
    $response->assertSee('characterPrompt(', false)->assertSee('CHARACTER TYPE: ASSASSIN');
});

test('every character type has a transparent square thumbnail to show in the picker', function () {
    foreach (array_keys(config('token_slayer.custom_character.types')) as $type) {
        $path = public_path("assets/custom-character/types/{$type}.png");

        expect(file_exists($path))->toBeTrue("missing thumbnail for {$type}");
        [$width, $height] = getimagesize($path);
        expect([$width, $height])->toBe([128, 128]);

        $image = imagecreatefrompng($path);
        expect((imagecolorat($image, 0, 0) >> 24) & 127)->toBe(127, "{$type} thumbnail has an opaque background");
    }
});

test('the picker shows each type with its thumbnail', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertSee('assets\/custom-character\/types', false)
        ->assertSee('x-bind:src="thumbs + \'/\' + key + \'.png\'"', false);
});

test('the nav opens other pages in the same tab and shows the current page as plain text', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $html = $this->get('/character')->assertOk()->getContent();

    expect($html)->not->toContain('target="_blank"');
    expect(preg_match_all('/<a [^>]*href="[^"]*\/(?:setup|guide|battlefield|dashboard)[^"]*"[^>]*>/', $html, $links))->toBeGreaterThan(0);
    foreach ($links[0] as $link) {
        expect($link)->not->toContain('target="_blank"');
    }
    expect($html)->toContain('aria-current="page"');
});

test('the editor offers an upload and no fighter to a player who has none', function () {
    editorFor(User::factory()->create())
        ->assertSee('Make your own fighter')
        ->assertSee('Build preview')
        ->assertDontSee('Your fighter')
        ->assertDontSee('Earlier versions');
});

test('the form submits to an action the browser can reach, not Livewire\'s built-in upload()', function () {
    editorFor(User::factory()->create())
        ->assertSeeHtml('wire:submit="build"')
        ->assertDontSeeHtml('wire:submit="upload"');
});

test('the editor tells the player why Build is disabled, and the size limits the decoder enforces', function () {
    editorFor(User::factory()->create())
        ->assertSee('Then build a preview and try it on the stage before you use it.')
        ->assertSee('2048 px')
        ->assertSee('death 3');
});

test('the editor is built from the sheet\'s own pieces: no browser dialogs, no pill progress, no second set of buttons', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)
        ->assertSeeHtml('class="btn primary"')
        ->assertDontSeeHtml('wire:confirm')
        ->assertDontSeeHtml('cce-btn')
        ->assertDontSeeHtml('cce-card');
});

test('the name box says how much of the limit is used', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)->assertSee('/ 24')->assertSeeHtml('maxlength="24"');
});

test('removing asks in the sheet first, naming what goes, with Cancel as the safe choice, and nothing is removed until it is confirmed', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $confirm->execute($user, stagedFor($user, 0)->version_hash);
    $confirm->execute($user, stagedFor($user, 1)->version_hash);

    editorFor($user)
        ->assertDontSeeHtml('role="alertdialog"')
        ->call('askToConfirm', 'remove')
        ->assertSet('confirming', 'remove')
        ->assertSeeHtml('role="alertdialog"')
        ->assertSee('and its 2 versions?')
        ->assertSeeHtml('x-init="$el.focus()"')
        ->call('dismissConfirm')
        ->assertSet('confirming', null)
        ->assertDontSeeHtml('role="alertdialog"')
        ->assertJs('setTimeout(() => document.getElementById("cce-remove-open-" + $wire.$id)?.focus(), 0)');

    expect($user->fresh()->customCharacter)->not->toBeNull();
});

test('deleting a version asks first too, and the answer is forgotten once it is done', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    $confirm->execute($user, stagedFor($user, 1)->version_hash);

    editorFor($user)
        ->call('askToConfirm', "version-{$first}")
        ->assertSee('Delete this version?')
        ->call('deleteVersion', $first)
        ->assertSet('confirming', null)
        ->assertDontSee('Delete this version?');
});

test('only a remove or one of the player\'s own version hashes can be asked about', function () {
    editorFor(CustomCharacter::factory()->create()->user)
        ->call('askToConfirm', 'something else')
        ->assertSet('confirming', null)
        ->call('askToConfirm', 'version-../../etc')
        ->assertSet('confirming', null);
});

test('removing the fighter clears the question', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)->call('askToConfirm', 'remove')->call('remove')->assertSet('confirming', null);
});

test('the sheet loads the editor when its panel is first opened, not with every battlefield page', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(App\Livewire\FighterSheet::class)
        ->call('open', 'character')
        ->assertSeeHtml('__lazyLoad');
});

test('a new player sees the pose layout and a file preview flow before building', function () {
    $user = User::factory()->create();

    editorFor($user)
        ->assertSee('Make your own fighter')
        ->assertSee('Prompt guide')
        ->assertSeeHtml('<svg role="img"')
        ->assertSeeHtml('phase:')
        ->assertSeeHtml('destroy()')
        ->assertDontSeeHtml('x-on:alpine:destroy')
        ->assertDontSeeHtml('info +=');
});

test('building a valid sheet sends a draft to the stage without equipping it', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();

    editorFor($user)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertHasNoErrors()
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['draft']['hash'] === $user->fresh()->customCharacterDraft->version_hash)
        ->assertDispatched('sheet-toast', message: 'Built. It goes live only when you use it.');

    expect($user->fresh()->customCharacterDraft)->not->toBeNull()
        ->and($user->fresh()->customCharacter)->toBeNull();
    Event::assertNotDispatched(FighterCharacterChanged::class);
});

test('a sheet that breaks the contract is refused with the reason, and nothing is saved', function () {
    $user = User::factory()->create();

    editorFor($user)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('Ask the image AI for one flat magenta (#FF00FF) background')
        ->assertSee('The background must be solid magenta');

    expect($user->fresh()->customCharacterDraft)->toBeNull();
});

test('a file that is not an image is refused', function () {
    editorFor(User::factory()->create())
        ->set('sheet', sheetUpload('not an image at all', 'sheet.png'))
        ->call('build')
        ->assertHasErrors('sheet');
});

test('building with no file chosen asks for one', function () {
    editorFor(User::factory()->create())->call('build')->assertHasErrors(['sheet' => 'required']);
});

test('a build is refused after too many attempts in an hour, and the stored fighter is untouched', function () {
    config(['token_slayer.custom_character.uploads_per_hour' => 1]);
    $user = User::factory()->create();
    $character = CustomCharacter::factory()->create(['user_id' => $user->id]);
    RateLimiter::clear("custom-character-upload:{$user->id}");

    $editor = editorFor($user);
    $editor->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))->call('build')->assertHasNoErrors();
    $editor->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))->call('build')->assertHasErrors('sheet');

    expect($user->fresh()->customCharacter->version_hash)->toBe($character->version_hash);
});

test('a second build while one is running is turned away with a message, not a crash', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $held = Cache::lock(CacheKeys::customCharacterBuild($user->id), 30);
    $held->get();

    editorFor($user)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('Another build of yours is still running');
});

test('confirming the previewed draft makes it the equipped fighter and tells the page around the editor', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();
    $draft = app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'magenta')));

    editorFor($user)
        ->call('confirm', $draft->version_hash)
        ->assertHasNoErrors()
        ->assertDispatched('sheet-toast', message: 'Your fighter is equipped.')
        ->assertSee('Equipped')
        ->assertDispatched('custom-character-changed', function (string $event, array $params) use ($user, $draft) {
            return $params['custom']['version'] === $draft->version_hash
                && $params['custom']['name'] === 'Custom'
                && $params['custom']['active'] === true
                && $params['equipped'] === 'custom';
        });

    expect($user->fresh()->customCharacter->version_hash)->toBe($draft->version_hash);
    Event::assertDispatched(FighterCharacterChanged::class);
});

test('confirming a draft that is gone shows why, and changes nothing', function () {
    $user = User::factory()->create();

    editorFor($user)
        ->call('confirm', 'abcdefabcdef')
        ->assertHasErrors('action')
        ->assertSee('There is no built fighter waiting for you');

    expect($user->fresh()->customCharacter)->toBeNull();
});

test('a broadcast that fails does not undo a confirm', function () {
    Event::listen(FighterCharacterChanged::class, fn () => throw new RuntimeException('reverb is down'));
    $user = User::factory()->create();
    $draft = app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'magenta')));

    editorFor($user)->call('confirm', $draft->version_hash)->assertHasNoErrors();

    expect($user->fresh()->customCharacter->version_hash)->toBe($draft->version_hash);
});

test('discarding the preview leaves the fighter as it was', function () {
    $user = User::factory()->create();
    $character = CustomCharacter::factory()->create(['user_id' => $user->id]);
    app(StageCustomCharacter::class)->execute($user, pngBytes(poseSheetImage(background: 'magenta')));

    editorFor($user)->call('discard')->assertDispatched('sheet-toast', message: 'Preview discarded. Your fighter is unchanged.')->assertDontSee('Previewing');

    expect($user->fresh()->customCharacterDraft)->toBeNull()
        ->and($user->fresh()->customCharacter->version_hash)->toBe($character->version_hash);
});

test('renaming shows the new name and tells the page around the editor', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)
        ->set('name', '  Sir Pixel ')
        ->call('rename')
        ->assertHasNoErrors()
        ->assertSet('name', 'Sir Pixel')
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['custom']['name'] === 'Sir Pixel');

    expect($character->fresh()->name)->toBe('Sir Pixel');
});

test('a name that is too long is refused with a reason', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)->set('name', str_repeat('x', 40))->call('rename')->assertHasErrors('name');

    expect($character->fresh()->name)->toBe('Custom');
});

test('the earlier versions list is there once there is more than one, with the one in use marked', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;

    editorFor($user)->assertDontSee('Earlier versions');

    $confirm->execute($user, stagedFor($user, 1)->version_hash);

    editorFor($user)->assertSee('Earlier versions')->assertSee('In use')
        ->assertSee('Version 1')->assertSee('Version 2')
        ->assertSee('Preview')->assertSee('Equip version 1')
        ->assertSeeHtml('custom-version-peek')
        ->call('equipVersion', $first)
        ->assertDispatched('sheet-toast', message: 'Switched to that version and equipped it.');

    expect($user->fresh()->customCharacter->version_hash)->toBe($first);
});

test('an earlier version can be deleted from the list, the one in use cannot', function () {
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    $second = $confirm->execute($user, stagedFor($user, 1)->version_hash)->version_hash;

    editorFor($user)
        ->call('deleteVersion', $second)
        ->assertHasErrors('action')
        ->call('deleteVersion', $first)
        ->assertHasNoErrors()
        ->assertDispatched('sheet-toast', message: 'Version deleted.');

    expect($user->fresh()->customCharacter->versions()->pluck('version_hash')->all())->toBe([$second]);
});

test('removing the fighter returns the player to their roster character and tells the page around the editor', function () {
    Event::fake([FighterCharacterChanged::class]);
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)
        ->call('remove')
        ->assertDispatched('sheet-toast', message: 'Fighter removed. Back to your roster fighter.')
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['custom'] === null && $params['equipped'] !== 'custom');

    expect(CustomCharacter::count())->toBe(0);
    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->customFighterState() === null);
});

test('removing when there is no fighter shows a message instead of failing', function () {
    editorFor(User::factory()->create())->call('remove')->assertHasErrors('action');
});

test('a draft of someone else is never shown', function () {
    $other = User::factory()->create();
    CustomCharacterDraft::create(['user_id' => $other->id, 'version_hash' => 'abcdefabcdef', 'animations' => CustomCharacter::factory()->make()->animations]);

    editorFor(User::factory()->create())->assertDontSee('Previewing');
});

test('confirming, switching or deleting while another change of the player\'s is running is turned away with a message', function () {
    config(['token_slayer.custom_character.lock_seconds' => 0]);
    $user = User::factory()->create();
    $confirm = app(ConfirmCustomCharacter::class);
    $first = $confirm->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    $confirm->execute($user, stagedFor($user, 1)->version_hash);
    $draft = stagedFor($user, 2);
    Cache::lock(CacheKeys::customCharacterBuild($user->id), 30)->get();

    editorFor($user)
        ->call('confirm', $draft->version_hash)->assertHasErrors('action')->assertSee('still running')
        ->call('equipVersion', $first)->assertHasErrors('action')
        ->call('deleteVersion', $first)->assertHasErrors('action')
        ->call('discard')->assertHasErrors('action')
        ->call('remove')->assertHasErrors('action');

    expect($user->fresh()->customCharacter->versions()->count())->toBe(2)
        ->and($user->fresh()->customCharacterDraft)->not->toBeNull();
});

test('another player\'s draft or version hash does nothing when it is sent through the component', function () {
    $user = User::factory()->create();
    $theirs = CustomCharacter::factory()->create();
    $theirDraft = stagedFor($theirs->user);

    editorFor($user)
        ->call('confirm', $theirDraft->version_hash)->assertHasErrors('action')
        ->call('equipVersion', $theirs->version_hash)->assertHasErrors('action')
        ->call('deleteVersion', $theirs->version_hash)->assertHasErrors('action');

    expect($theirs->fresh())->not->toBeNull()
        ->and($theirs->user->fresh()->customCharacterDraft)->not->toBeNull()
        ->and($user->fresh()->customCharacter)->toBeNull();
});

test('building announces the draft to the stage, with no ceremony', function () {
    $user = User::factory()->create();

    editorFor($user)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertDispatched('custom-character-changed', function (string $event, array $params) use ($user) {
            return $params['draft']['hash'] === $user->fresh()->customCharacterDraft->version_hash
                && $params['draft']['spec']['version'] === $params['draft']['hash']
                && $params['ceremony'] === false
                && $params['custom'] === null;
        });
});

test('confirming announces the new fighter with a ceremony and no draft left', function () {
    $user = User::factory()->create();
    $draft = stagedFor($user);

    editorFor($user)
        ->call('confirm', $draft->version_hash)
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['draft'] === null
            && $params['ceremony'] === true
            && $params['equipped'] === 'custom'
            && $params['custom']['version'] === $draft->version_hash)
        ->assertDispatched('sheet-focus', target: 'equipped-slot');
});

test('renaming or discarding is announced without a ceremony', function () {
    $character = CustomCharacter::factory()->create();
    stagedFor($character->user);

    editorFor($character->user)
        ->call('discard')
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['draft'] === null && $params['ceremony'] === false);
});

test('the stage can equip its draft by hash through an event, and only the player\'s own', function () {
    $user = User::factory()->create();
    $draft = stagedFor($user);

    editorFor($user)
        ->dispatch('custom-draft-confirm', hash: 'abcdefabcdef')
        ->assertHasErrors('action')
        ->dispatch('custom-draft-confirm', hash: $draft->version_hash)
        ->assertHasNoErrors();

    expect($user->fresh()->customCharacter->version_hash)->toBe($draft->version_hash);
});

test('the stage can discard its draft through an event', function () {
    $user = User::factory()->create();
    stagedFor($user);

    editorFor($user)->dispatch('custom-draft-discard');

    expect($user->fresh()->customCharacterDraft)->toBeNull();
});

test('the stage can equip a saved version through an event, never another player\'s', function () {
    $user = User::factory()->create();
    $first = app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user, 0)->version_hash)->version_hash;
    app(ConfirmCustomCharacter::class)->execute($user, stagedFor($user, 1)->version_hash);
    $theirs = CustomCharacter::factory()->create();

    editorFor($user)
        ->dispatch('custom-version-equip', hash: $theirs->version_hash)
        ->assertHasErrors('action');
    expect($user->fresh()->customCharacter->version_hash)->not->toBe($theirs->version_hash);

    editorFor($user)->dispatch('custom-version-equip', hash: $first)->assertHasNoErrors();
    expect($user->fresh()->customCharacter->version_hash)->toBe($first);
});

test('a failed action tells the sheet, and refreshes it so a stale draft slot corrects itself', function () {
    $user = User::factory()->create();

    editorFor($user)
        ->call('confirm', 'abcdefabcdef')
        ->assertHasErrors('action')
        ->assertDispatched('sheet-error', fn (string $event, array $params) => str_contains($params['message'], 'no built fighter'))
        ->assertDispatched('custom-character-changed', fn (string $event, array $params) => $params['draft'] === null && $params['ceremony'] === false);
});

test('after a discard focus goes to the file button, and after a remove back to what opened the editor', function () {
    $character = CustomCharacter::factory()->create();
    stagedFor($character->user);

    editorFor($character->user)
        ->call('discard')
        ->assertDispatched('sheet-focus', target: 'upload')
        ->call('remove')
        ->assertDispatched('sheet-focus', target: 'opener');
});

test('the file button and the name box carry the hooks the stage focuses by', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)->assertSeeHtml('data-focus="upload"')->assertSeeHtml('data-focus="name"');
});

test('the prompt guide stays one click away for a player who already has a fighter', function () {
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)->assertSee('Prompt guide')->assertSeeHtml(route('custom-character'));
});
