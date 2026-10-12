<?php

use App\Events\FighterCharacterChanged;
use App\Livewire\CustomCharacterUpload;
use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    RateLimiter::clear('custom-character-upload:1');
});

/**
 * A fake upload holding the given bytes.
 *
 * @param  string  $bytes  file content
 * @param  string  $name  file name
 * @return UploadedFile
 */
function sheetUpload(string $bytes, string $name = 'sheet.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $bytes);
}

test('the page sends guests to the slack login', function () {
    $this->get('/character')->assertRedirect(route('slack.login'));
});

test('the page lets the user pick a character type before it offers a prompt', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $response = $this->get('/character')->assertOk()->assertSee('Choose a character type to get its prompt.');

    foreach (['Warrior', 'Wizard', 'Assassin', 'Archer', 'Healer', 'Necromancer', 'Knight', 'Alchemist', 'Berserker'] as $label) {
        $response->assertSee($label);
    }
    $response->assertSee('characterPrompt(', false)->assertSee('CHARACTER TYPE: ASSASSIN');
});

test('the guide says to pick a costume by character type, then copy the prompt into ChatGPT with a picture of the character', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertSee('Pick a costume by character type below')
        ->assertSee('copy its prompt and paste it into ChatGPT')
        ->assertSee('together with a picture of your custom character')
        ->assertSee('to make your pose sheet');
});

test('the page shows the prompt to copy and an upload form', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertOk()
        ->assertSee('solid magenta')
        ->assertSee('Upload');
});

test('the form submits to an action the browser can reach, not Livewire\'s built-in upload()', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertSee('wire:submit="build"', false)
        ->assertDontSee('wire:submit="upload"', false);
});

test('a built fighter is confirmed on the page and the view scrolls up to it, a refused sheet is not', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build')
        ->assertSet('notice', null)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertSet('notice', 'Your fighter is ready! Here it is.')
        ->assertSee('Your fighter is ready!')
        ->assertJs('window.scrollTo({ top: 0, behavior: "smooth" })');
});

test('a valid pose sheet becomes the user\'s character', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertHasNoErrors();

    $character = $user->fresh()->customCharacter;

    expect($character)->not->toBeNull();
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$character->version_hash}/walk.png");
});

test('a sheet that breaks the contract is refused with the reason, and nothing is saved', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('background must be solid magenta');

    expect(CustomCharacter::count())->toBe(0);
});

test('a file that is not an image is refused', function () {
    Livewire::actingAs(User::factory()->create())->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload('this is not a picture'))
        ->call('build')
        ->assertHasErrors('sheet');
});

test('uploading with no file chosen asks for one', function () {
    Livewire::actingAs(User::factory()->create())->test(CustomCharacterUpload::class)
        ->call('build')
        ->assertHasErrors(['sheet' => 'required']);
});

test('the page previews the saved character\'s strips', function () {
    $user = User::factory()->create();
    CustomCharacter::factory()->for($user)->create(['version_hash' => 'abc123def456']);

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->assertSee("custom-characters/{$user->id}/abc123def456/walk.png");
});

test('removing the character deletes it', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build');

    $component->call('remove');

    expect($user->fresh()->customCharacter)->toBeNull();
});

test('uploads are rate limited per user', function () {
    config(['token_slayer.custom_character.uploads_per_hour' => 2]);
    $user = User::factory()->create();
    RateLimiter::clear("custom-character-upload:{$user->id}");

    $component = Livewire::actingAs($user)->test(CustomCharacterUpload::class);

    foreach (range(1, 2) as $attempt) {
        $component->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))->call('build')->assertHasNoErrors();
    }

    $component->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee('Too many uploads');
});

test('building a fighter tells the battlefield, so everyone sees it without a reload', function () {
    Event::fake([FighterCharacterChanged::class]);
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('build');

    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($user));
});

test('a refused sheet tells the battlefield nothing', function () {
    Event::fake([FighterCharacterChanged::class]);

    Livewire::actingAs(User::factory()->create())->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('build');

    Event::assertNotDispatched(FighterCharacterChanged::class);
});

test('removing the fighter tells the battlefield, which puts the roster character back', function () {
    $character = CustomCharacter::factory()->create();
    Event::fake([FighterCharacterChanged::class]);

    Livewire::actingAs($character->user)->test(CustomCharacterUpload::class)->call('remove');

    Event::assertDispatched(FighterCharacterChanged::class, fn ($event) => $event->user->is($character->user));
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
