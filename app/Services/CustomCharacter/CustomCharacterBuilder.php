<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\InvalidPoseSheetException;
use GdImage;

/**
 * Turns an uploaded pose sheet into the four fighter strips: decode and check
 * the file, remove the background, find the poses, erase stray marks, check
 * the poses are usable, shrink them to sprite size on one palette, tone the
 * colours to the roster, outline them, and lay them into 100 px frames.
 */
class CustomCharacterBuilder
{
    /**
     * Create the builder.
     *
     * @param  PoseSheetDecoder  $decoder  checks and decodes the uploaded file
     * @param  PoseSheetKeyer  $keyer  removes the background
     * @param  PoseSheetLocator  $locator  finds the poses
     * @param  StrayMarkRemover  $strayMarkRemover  erases motion lines and sparks
     * @param  PoseSheetValidator  $validator  checks the poses can be used together
     * @param  SpritePixelator  $pixelator  shrinks the poses onto one scale and palette
     * @param  RosterToneMatcher  $toneMatcher  pulls the colours toward the roster's look
     * @param  SpriteOutliner  $outliner  draws the dark outline
     * @param  SpriteStripBuilder  $stripBuilder  lays the sprites into animation strips
     * @return void
     */
    public function __construct(
        private readonly PoseSheetDecoder $decoder,
        private readonly PoseSheetKeyer $keyer,
        private readonly PoseSheetLocator $locator,
        private readonly StrayMarkRemover $strayMarkRemover,
        private readonly PoseSheetValidator $validator,
        private readonly SpritePixelator $pixelator,
        private readonly RosterToneMatcher $toneMatcher,
        private readonly SpriteOutliner $outliner,
        private readonly SpriteStripBuilder $stripBuilder,
    ) {}

    /**
     * Builds the animation strips from an uploaded pose sheet.
     *
     * @param  string  $bytes  the uploaded file's bytes
     * @return array<string, array{image: GdImage, frames: int, rate: int}> strips keyed by animation
     *
     * @throws InvalidPoseSheetException when the sheet does not meet the pose sheet contract
     */
    public function execute(string $bytes): array
    {
        $sheet = $this->keyer->key($this->decoder->decode($bytes));

        [$sheet, $poses] = $this->strayMarkRemover->remove($sheet, $this->locator->locate($sheet));

        $this->validator->assertUsable($poses, $sheet->width, $sheet->height);

        $sprites = $this->toneMatcher->match($this->pixelator->pixelate($sheet, $poses));

        $outlined = array_map(
            fn (array $row): array => array_map(fn (Sprite $sprite): Sprite => $this->outliner->outline($sprite), $row),
            $sprites,
        );

        return $this->stripBuilder->build($outlined);
    }
}
