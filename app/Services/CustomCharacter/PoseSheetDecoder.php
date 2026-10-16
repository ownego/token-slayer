<?php

namespace App\Services\CustomCharacter;

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use GdImage;

/**
 * Turns an uploaded file into a GD bitmap, after checking the cheap things
 * first: byte size, real image type and dimensions read from the header, so
 * an oversized or hostile file is refused before any pixel is decoded.
 */
class PoseSheetDecoder
{
    /**
     * The only image types a pose sheet may use.
     *
     * @var array<int, int>
     */
    private const array ALLOWED_TYPES = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];

    /**
     * Decodes the file, or throws when it is not an acceptable pose sheet image.
     *
     * @param  string  $bytes  the uploaded file's bytes
     * @return GdImage the decoded bitmap
     *
     * @throws InvalidPoseSheetException when the file fails a size, type or dimension check
     */
    public function decode(string $bytes): GdImage
    {
        if (strlen($bytes) > (int) config('token_slayer.custom_character.max_bytes')) {
            throw new InvalidPoseSheetException(PoseSheetProblem::FileTooLarge);
        }

        $header = @getimagesizefromstring($bytes);

        if ($header === false) {
            throw new InvalidPoseSheetException(PoseSheetProblem::NotAnImage);
        }

        if (! in_array($header[2], self::ALLOWED_TYPES, true)) {
            throw new InvalidPoseSheetException(PoseSheetProblem::UnsupportedType);
        }

        $this->assertDimensionsAllowed($header[0], $header[1]);

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new InvalidPoseSheetException(PoseSheetProblem::NotAnImage);
        }

        return $image;
    }

    /**
     * Throws when a side is under the minimum or over the maximum, or the
     * whole bitmap is over the pixel budget.
     *
     * @param  int  $width  image width in px
     * @param  int  $height  image height in px
     * @return void
     *
     * @throws InvalidPoseSheetException when the dimensions are outside the configured limits
     */
    private function assertDimensionsAllowed(int $width, int $height): void
    {
        $limits = config('token_slayer.custom_character');

        if (min($width, $height) < (int) $limits['min_side']) {
            throw new InvalidPoseSheetException(PoseSheetProblem::DimensionsTooSmall);
        }

        if (max($width, $height) > (int) $limits['max_side'] || $width * $height > (int) $limits['max_pixels']) {
            throw new InvalidPoseSheetException(PoseSheetProblem::DimensionsTooLarge);
        }
    }
}
