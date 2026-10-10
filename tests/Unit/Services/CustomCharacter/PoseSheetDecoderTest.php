<?php

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use App\Services\CustomCharacter\PoseSheetDecoder;

/**
 * Runs the decoder and returns the problem it rejected the file with, or null
 * when the file was accepted.
 *
 * @param  string  $bytes  the uploaded file's bytes
 * @return ?PoseSheetProblem
 */
function decoderRejection(string $bytes): ?PoseSheetProblem
{
    try {
        app(PoseSheetDecoder::class)->decode($bytes);
    } catch (InvalidPoseSheetException $exception) {
        return $exception->problem;
    }

    return null;
}

test('decodes a supported image at its own size', function (string $format) {
    $image = app(PoseSheetDecoder::class)->decode(imageBytes(640, 512, $format));

    expect(imagesx($image))->toBe(640)
        ->and(imagesy($image))->toBe(512);
})->with([
    'png' => ['png'],
    'jpeg' => ['jpeg'],
    'webp' => ['webp'],
]);

test('rejects bytes that are not an image', function () {
    expect(decoderRejection('definitely not a picture'))->toBe(PoseSheetProblem::NotAnImage);
});

test('rejects an image type outside the allowed list', function () {
    expect(decoderRejection(imageBytes(640, 512, 'gif')))->toBe(PoseSheetProblem::UnsupportedType);
});

test('rejects a file above the byte limit', function () {
    config(['token_slayer.custom_character.max_bytes' => 1000]);

    expect(decoderRejection(imageBytes(640, 512)))->toBe(PoseSheetProblem::FileTooLarge);
});

test('rejects an image too small to hold the poses', function (int $width, int $height) {
    expect(decoderRejection(imageBytes($width, $height)))->toBe(PoseSheetProblem::DimensionsTooSmall);
})->with([
    'narrow' => [511, 800],
    'short' => [800, 511],
]);

test('rejects an image above the side limit', function () {
    expect(decoderRejection(imageBytes(4097, 600)))->toBe(PoseSheetProblem::DimensionsTooLarge);
});

test('rejects an image above the pixel budget even when each side is allowed', function () {
    config(['token_slayer.custom_character.max_pixels' => 500_000]);

    expect(decoderRejection(imageBytes(800, 800)))->toBe(PoseSheetProblem::DimensionsTooLarge);
});
