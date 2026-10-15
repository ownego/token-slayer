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
 * The editor for a user, as the page or the sheet mounts it.
 *
 * @param  User  $user  who is signed in
 * @param  string  $variant  'page' or 'sheet'
 * @return Livewire\Features\SupportTesting\Testable
 */
function editorFor(User $user, string $variant = 'page')
{
    return Livewire::actingAs($user)->test(CustomCharacterEditor::class, ['variant' => $variant]);
}

test('the page sends guests to the slack login', function () {
    $this->get('/character')->assertRedirect();
});

test('the page leads with the guide and has the editor below it', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')->assertOk()
        ->assertSee('1. Ask an image AI for a pose sheet')
        ->assertSee('2. Upload, preview and manage')
        ->assertSeeLivewire(CustomCharacterEditor::class);
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

test('the nav opens other pages in a new tab and shows the current page as plain text, not a link', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $html = $this->get('/character')->assertOk()->getContent();

    expect($html)->toContain('target="_blank" rel="noopener"')
        ->and(preg_match_all('/<a [^>]*href="[^"]*\/(?:setup|guide|battlefield|dashboard)[^"]*"[^>]*>/', $html, $links))->toBeGreaterThan(0);
    foreach ($links[0] as $link) {
        expect($link)->toContain('target="_blank"');
    }
    expect($html)->toContain('aria-current="page"');
});

test('the editor offers an upload and no fighter to a player who has none', function () {
    editorFor(User::factory()->create())
        ->assertSee('Upload your sheet')
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
        ->assertSee('Choose a file first.')
        ->assertSee('2048 px a side')
        ->assertSee('defeated (3)');
});

test('the sheet variant only changes the look', function () {
    editorFor(User::factory()->create(), 'sheet')->assertSeeHtml('cce--sheet');
    editorFor(User::factory()->create())->assertSeeHtml('cce--page')->assertDontSeeHtml('cce--sheet');
});

test('building a valid sheet previews it as a draft: nothing goes live yet', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();

    editorFor($user)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertHasNoErrors()
        ->assertSee('not live yet')
        ->assertSee('Use this fighter')
        ->assertSee('Discard')
        ->assertSee('Built. Have a look below')
        ->assertSee('Defeated')
        ->assertJs('document.getElementById("cce-draft-" + $wire.$id)?.scrollIntoView({ behavior: "smooth", block: "center" })');

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
        ->assertSee('Your fighter is ready and equipped.')
        ->assertSee('equipped')
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

    editorFor($user)->call('discard')->assertSee('Discarded.')->assertDontSee('not live yet');

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

    editorFor($user)->assertSee('Earlier versions')->assertSee('in use')->assertSee('Equip this one')
        ->call('equipVersion', $first)
        ->assertSee('Switched to that version and equipped it.');

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
        ->assertSee('Version deleted.');

    expect($user->fresh()->customCharacter->versions()->pluck('version_hash')->all())->toBe([$second]);
});

test('removing the fighter returns the player to their roster character and tells the page around the editor', function () {
    Event::fake([FighterCharacterChanged::class]);
    $character = CustomCharacter::factory()->create();

    editorFor($character->user)
        ->call('remove')
        ->assertSee('You are back to your roster character.')
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

    editorFor(User::factory()->create())->assertDontSee('not live yet');
});
