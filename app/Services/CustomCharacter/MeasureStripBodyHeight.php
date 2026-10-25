<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\BlankStripException;
use GdImage;

/**
 * Measures how tall a built strip's character is: the span of its first frame,
 * from the first row that holds ink to the last. The pixel height of a fighter
 * varies with the version that built it (old ones were taller), so a minion's size
 * is taken from what it actually draws, not from the build settings. A transparent
 * row inside the body counts as part of it: the client draws the minion as tall as
 * the span, measured from its feet.
 */
class MeasureStripBodyHeight
{
    /**
     * The tallest body the client can draw: the distance from the top of the frame
     * to the feet row. A body_px is valid from 1 to here, on the server and the client alike.
     *
     * @var int
     */
    public const int MAX = SpriteStripBuilder::FOOT_ROW;

    /**
     * The rows from the first one in the strip's first frame that holds a pixel that
     * is not fully transparent to the last one, both included, at most MAX.
     *
     * @param  GdImage  $strip  a built strip, frames side by side
     * @return int from 1 to MAX
     *
     * @throws BlankStripException when no pixel of the first frame has ink
     */
    public function execute(GdImage $strip): int
    {
        $width = min(SpriteStripBuilder::FRAME, imagesx($strip));
        $height = min(SpriteStripBuilder::FRAME, imagesy($strip));
        $first = null;
        $last = null;

        for ($y = 0; $y < $height; $y++) {
            if ($this->rowHoldsInk($strip, $y, $width)) {
                $first ??= $y;
                $last = $y;
            }
        }

        if ($first === null || $last === null) {
            throw new BlankStripException;
        }

        return min($last - $first + 1, self::MAX);
    }

    /**
     * Whether any pixel of a row, within the first frame, is not fully transparent.
     *
     * @param  GdImage  $strip  the strip
     * @param  int  $y  the row
     * @param  int  $width  how many columns to look at
     * @return bool
     */
    private function rowHoldsInk(GdImage $strip, int $y, int $width): bool
    {
        for ($x = 0; $x < $width; $x++) {
            if (((imagecolorat($strip, $x, $y) >> 24) & 0x7F) < 127) {
                return true;
            }
        }

        return false;
    }
}
