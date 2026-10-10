<?php

namespace App\Services\CustomCharacter;

/**
 * The bounding box of one pose's ink on the sheet, in sheet pixels.
 */
final readonly class PoseBox
{
    /**
     * Build the box.
     *
     * @param  int  $left  x of the leftmost ink column
     * @param  int  $top  y of the topmost ink row
     * @param  int  $width  box width in px
     * @param  int  $height  box height in px
     * @return void
     */
    public function __construct(
        public int $left,
        public int $top,
        public int $width,
        public int $height,
    ) {}
}
