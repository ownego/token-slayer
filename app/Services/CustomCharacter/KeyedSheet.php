<?php

namespace App\Services\CustomCharacter;

use GdImage;

/**
 * A pose sheet with its background removed: the decoded bitmap plus a
 * per-pixel mask of which pixels are character ink.
 */
final readonly class KeyedSheet
{
    /**
     * Build the keyed sheet.
     *
     * @param  GdImage  $image  the true-colour bitmap the mask was computed from
     * @param  int  $width  bitmap width in px
     * @param  int  $height  bitmap height in px
     * @param  string  $solid  row-major mask, one byte per pixel: "\1" for ink, "\0" for background
     * @return void
     */
    public function __construct(
        public GdImage $image,
        public int $width,
        public int $height,
        private string $solid,
    ) {}

    /**
     * A copy of the sheet with the given pixels turned into background.
     *
     * @param  array<int, int>  $indices  row-major pixel indices (y * width + x) to clear
     * @return self
     */
    public function cleared(array $indices): self
    {
        $solid = $this->solid;

        foreach ($indices as $index) {
            $solid[$index] = "\0";
        }

        return new self($this->image, $this->width, $this->height, $solid);
    }

    /**
     * Whether the pixel is character ink rather than background.
     *
     * @param  int  $x  column
     * @param  int  $y  row
     * @return bool
     */
    public function isSolid(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return false;
        }

        return $this->solid[$y * $this->width + $x] === "\1";
    }
}
