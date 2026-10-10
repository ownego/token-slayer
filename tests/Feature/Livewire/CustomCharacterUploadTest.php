<?php

use App\Livewire\CustomCharacterUpload;
use App\Models\CustomCharacter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

test('the page shows the prompt to copy and an upload form', function () {
    $this->withoutVite()->actingAs(User::factory()->create());

    $this->get('/character')
        ->assertOk()
        ->assertSee('solid magenta')
        ->assertSee('Upload');
});

test('a valid pose sheet becomes the user\'s character', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('upload')
        ->assertHasNoErrors();

    $character = $user->fresh()->customCharacter;

    expect($character)->not->toBeNull();
    Storage::disk('public')->assertExists("custom-characters/{$user->id}/{$character->version_hash}/walk.png");
});

test('a sheet that breaks the contract is refused with the reason, and nothing is saved', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'white'))))
        ->call('upload')
        ->assertHasErrors('sheet')
        ->assertSee('background must be solid magenta');

    expect(CustomCharacter::count())->toBe(0);
});

test('a file that is not an image is refused', function () {
    Livewire::actingAs(User::factory()->create())->test(CustomCharacterUpload::class)
        ->set('sheet', sheetUpload('this is not a picture'))
        ->call('upload')
        ->assertHasErrors('sheet');
});

test('uploading with no file chosen asks for one', function () {
    Livewire::actingAs(User::factory()->create())->test(CustomCharacterUpload::class)
        ->call('upload')
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
        ->call('upload');

    $component->call('remove');

    expect($user->fresh()->customCharacter)->toBeNull();
});

test('uploads are rate limited per user', function () {
    config(['token_slayer.custom_character.uploads_per_hour' => 2]);
    $user = User::factory()->create();
    RateLimiter::clear("custom-character-upload:{$user->id}");

    $component = Livewire::actingAs($user)->test(CustomCharacterUpload::class);

    foreach (range(1, 2) as $attempt) {
        $component->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))->call('upload')->assertHasNoErrors();
    }

    $component->set('sheet', sheetUpload(pngBytes(poseSheetImage(background: 'magenta'))))
        ->call('upload')
        ->assertHasErrors('sheet')
        ->assertSee('Too many uploads');
});
