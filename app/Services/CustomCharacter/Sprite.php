<?php

namespace App\Services\CustomCharacter;

use GdImage;

/**
 * One pixelated pose: a small true-colour bitmap whose pixels are either
 * fully opaque or fully transparent.
 */
final readonly class Sprite
{
    /**
     * Build the sprite.
     *
     * @param  int  $width  bitmap width in px
     * @param  int  $height  bitmap height in px
     * @param  GdImage  $image  true-colour bitmap with an alpha channel
     * @return void
     */
    public function __construct(
        public int $width,
        public int $height,
        public GdImage $image,
    ) {}

    /**
     * Whether the pixel is drawn rather than transparent.
     *
     * @param  int  $x  column
     * @param  int  $y  row
     * @return bool
     */
    public function isOpaque(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return false;
        }

        return ((imagecolorat($this->image, $x, $y) >> 24) & 0x7F) === 0;
    }
}
