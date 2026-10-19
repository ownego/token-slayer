<?php

namespace App\Services\CustomCharacter;

use GdImage;

/**
 * Measures how tall a built strip's character is: the rows of its first frame
 * that hold any ink. The pixel height of a fighter varies with the version that
 * built it (old ones were taller), so a minion's size is taken from what it
 * actually draws, not from the build settings.
 */
class MeasureStripBodyHeight
{
    /**
     * The number of rows in the strip's first frame that hold at least one pixel
     * that is not fully transparent.
     *
     * @param  GdImage  $strip  a built strip, frames side by side
     * @return int
     */
    public function execute(GdImage $strip): int
    {
        $width = min(SpriteStripBuilder::FRAME, imagesx($strip));
        $height = min(SpriteStripBuilder::FRAME, imagesy($strip));
        $rows = 0;

        for ($y = 0; $y < $height; $y++) {
            if ($this->rowHoldsInk($strip, $y, $width)) {
                $rows++;
            }
        }

        return $rows;
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
