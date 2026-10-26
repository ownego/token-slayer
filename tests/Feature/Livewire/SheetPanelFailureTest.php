<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\BlankStripException;
use App\Exceptions\CustomCharacterBusyException;
use App\Exceptions\CustomCharacterDraftMissingException;
use App\Exceptions\CustomCharacterStorageException;
use App\Exceptions\CustomCharacterVersionException;
use App\Exceptions\CustomMinionDraftMissingException;
use App\Exceptions\CustomMinionDuplicateException;
use App\Exceptions\CustomMinionMissingException;
use App\Exceptions\CustomMinionNoFighterException;
use App\Exceptions\CustomMinionSetFullException;
use App\Exceptions\InvalidPoseSheetException;
use App\Exceptions\MinionLookUnknownException;
use App\Exceptions\PlayerFacingException;
use App\Livewire\CustomCharacterEditor;
use App\Models\Boss;
use App\Models\User;
use App\Services\CustomCharacter\ConfirmCustomCharacter;
use App\Services\CustomCharacter\StageCustomCharacter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('public');
    Boss::factory()->create(['status' => 'alive', 'number' => 1]);
});

/**
 * An exception made up for the test that a player is meant to read: it extends the
 * base class and is listed nowhere.
 *
 * @param  string  $message  what the player reads
 * @return PlayerFacingException
 */
function madeUpPlayerFacingException(string $message): PlayerFacingException
{
    return new class($message) extends PlayerFacingException {};
}

/**
 * Makes the editor's confirm action throw the given exception.
 *
 * @param  Throwable  $exception  what the service throws
 * @return void
 */
function confirmThrows(Throwable $exception): void
{
    $mock = Mockery::mock(ConfirmCustomCharacter::class);
    $mock->shouldReceive('execute')->andThrow($exception);
    app()->instance(ConfirmCustomCharacter::class, $mock);
}

test('an exception that extends PlayerFacingException shows as a message and tells the sheet, with no list to edit', function () {
    confirmThrows(madeUpPlayerFacingException('Out of cheese.'));

    Livewire::actingAs(User::factory()->create())->test(CustomCharacterEditor::class)
        ->call('confirm', 'abcdefabcdef')
        ->assertHasErrors('action')
        ->assertSee('Out of cheese.')
        ->assertDispatched('sheet-error', fn (string $event, array $params) => $params['message'] === 'Out of cheese.')
        ->assertDispatched('custom-character-changed');
});

test('an exception that is not meant for the player is let through, not hidden behind a message', function () {
    confirmThrows(new RuntimeException('disk exploded'));

    $editor = Livewire::actingAs(User::factory()->create())->test(CustomCharacterEditor::class);

    expect(fn () => $editor->call('confirm', 'abcdefabcdef'))->toThrow(RuntimeException::class, 'disk exploded');
});

test('a bad pose sheet shows under the file box with its hint, not as a sheet-error', function () {
    $mock = Mockery::mock(StageCustomCharacter::class);
    $mock->shouldReceive('execute')->andThrow(new InvalidPoseSheetException(PoseSheetProblem::NotAnImage));
    app()->instance(StageCustomCharacter::class, $mock);

    Livewire::actingAs(User::factory()->create())->test(CustomCharacterEditor::class)
        ->set('sheet', UploadedFile::fake()->image('sheet.png'))
        ->call('build')
        ->assertHasErrors('sheet')
        ->assertSee(PoseSheetProblem::NotAnImage->hint())
        ->assertNotDispatched('sheet-error');
});

test('the exceptions a player can be told about all extend PlayerFacingException, and the pose sheet one stays apart', function () {
    $playerFacing = [
        BlankStripException::class,
        CustomCharacterBusyException::class,
        CustomCharacterDraftMissingException::class,
        CustomCharacterStorageException::class,
        CustomCharacterVersionException::class,
        CustomMinionDraftMissingException::class,
        CustomMinionDuplicateException::class,
        CustomMinionMissingException::class,
        CustomMinionNoFighterException::class,
        CustomMinionSetFullException::class,
        MinionLookUnknownException::class,
    ];

    foreach ($playerFacing as $class) {
        expect(is_subclass_of($class, PlayerFacingException::class))->toBeTrue();
    }

    expect(is_subclass_of(InvalidPoseSheetException::class, PlayerFacingException::class))->toBeFalse();
});
