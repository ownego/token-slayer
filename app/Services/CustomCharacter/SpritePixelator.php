<?php

namespace App\Services\CustomCharacter;

use GdImage;

/**
 * Shrinks every located pose to game-sprite size. One scale is shared by all
 * poses (set by the median idle/walk height) so they stay in proportion, each
 * output pixel is the average of only the ink pixels it covers so the
 * background never tints an edge, and one palette is shared by all poses so
 * colours cannot drift from frame to frame.
 */
class SpritePixelator
{
    /**
     * Share of a block's source pixels that must be ink for the output pixel to be drawn.
     *
     * @var float
     */
    private const float COVERAGE = 0.55;

    /**
     * Rows whose median height fixes the shared scale.
     *
     * @var array<int, string>
     */
    private const array REFERENCE_ROWS = ['idle', 'walk'];

    /**
     * Create the pixelator.
     *
     * @param  PaletteQuantizer  $quantizer  reduces all poses to one shared palette
     * @return void
     */
    public function __construct(private readonly PaletteQuantizer $quantizer) {}

    /**
     * Shrinks the poses to sprites that share one scale and one palette.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet the poses were located on
     * @param  array<string, array<int, PoseBox>>  $poses  located poses keyed by row name
     * @return array<string, array<int, Sprite>> sprites in the same order and keys as the poses
     */
    public function pixelate(KeyedSheet $sheet, array $poses): array
    {
        $scale = (int) config('token_slayer.custom_character.sprite_height') / $this->referenceHeight($poses);
        $shrunk = [];
        $weights = [];

        foreach ($poses as $name => $boxes) {
            foreach ($boxes as $index => $box) {
                $shrunk[$name][$index] = $this->shrink($sheet, $box, $scale);

                foreach ($shrunk[$name][$index]['pixels'] as $colour) {
                    if ($colour !== null) {
                        $weights[$colour] = ($weights[$colour] ?? 0) + 1;
                    }
                }
            }
        }

        $palette = $this->quantizer->quantize($weights, (int) config('token_slayer.custom_character.palette_colors'));

        return array_map(
            fn (array $row): array => array_map(fn (array $pose): Sprite => $this->toSprite($pose, $palette), $row),
            $shrunk,
        );
    }

    /**
     * Median height of the idle and walk poses, the yardstick for the scale.
     *
     * @param  array<string, array<int, PoseBox>>  $poses  located poses keyed by row name
     * @return int height in px, at least 1
     */
    private function referenceHeight(array $poses): int
    {
        $heights = [];

        foreach (self::REFERENCE_ROWS as $name) {
            foreach ($poses[$name] ?? [] as $box) {
                $heights[] = $box->height;
            }
        }

        sort($heights);

        return max(1, $heights[intdiv(count($heights), 2)] ?? 1);
    }

    /**
     * Box-filters one pose down by the scale, averaging only ink pixels.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  PoseBox  $box  the pose's bounding box
     * @param  float  $scale  output pixels per source pixel
     * @return array{width: int, height: int, pixels: array<int, ?int>} row-major 0xRRGGBB colours, null where empty
     */
    private function shrink(KeyedSheet $sheet, PoseBox $box, float $scale): array
    {
        $width = max(1, (int) round($box->width * $scale));
        $height = max(1, (int) round($box->height * $scale));
        $pixels = [];

        for ($outY = 0; $outY < $height; $outY++) {
            $fromY = $box->top + (int) floor($outY / $scale);
            $toY = max($fromY + 1, min($box->top + $box->height, $box->top + (int) ceil(($outY + 1) / $scale)));

            for ($outX = 0; $outX < $width; $outX++) {
                $fromX = $box->left + (int) floor($outX / $scale);
                $toX = max($fromX + 1, min($box->left + $box->width, $box->left + (int) ceil(($outX + 1) / $scale)));

                $pixels[$outY * $width + $outX] = $this->averageInk($sheet, $fromX, $toX, $fromY, $toY);
            }
        }

        return ['width' => $width, 'height' => $height, 'pixels' => $pixels];
    }

    /**
     * Average colour of the ink pixels in a block, or null when ink covers too little of it.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  int  $fromX  first column (inclusive)
     * @param  int  $toX  last column (exclusive)
     * @param  int  $fromY  first row (inclusive)
     * @param  int  $toY  last row (exclusive)
     * @return ?int 0xRRGGBB or null
     */
    private function averageInk(KeyedSheet $sheet, int $fromX, int $toX, int $fromY, int $toY): ?int
    {
        $red = $green = $blue = $ink = 0;

        for ($y = $fromY; $y < $toY; $y++) {
            for ($x = $fromX; $x < $toX; $x++) {
                if ($sheet->isSolid($x, $y)) {
                    $argb = imagecolorat($sheet->image, $x, $y);
                    $red += ($argb >> 16) & 0xFF;
                    $green += ($argb >> 8) & 0xFF;
                    $blue += $argb & 0xFF;
                    $ink++;
                }
            }
        }

        $block = ($toX - $fromX) * ($toY - $fromY);

        if ($ink === 0 || $ink / $block < self::COVERAGE) {
            return null;
        }

        return ((int) round($red / $ink) << 16) | ((int) round($green / $ink) << 8) | (int) round($blue / $ink);
    }

    /**
     * Paints a shrunk pose into a GD sprite using the shared palette.
     *
     * @param  array{width: int, height: int, pixels: array<int, ?int>}  $pose  the shrunk pose
     * @param  array<int, int>  $palette  original colour => palette colour
     * @return Sprite
     */
    private function toSprite(array $pose, array $palette): Sprite
    {
        $image = $this->transparentImage($pose['width'], $pose['height']);

        foreach ($pose['pixels'] as $index => $colour) {
            if ($colour !== null) {
                imagesetpixel($image, $index % $pose['width'], intdiv($index, $pose['width']), $palette[$colour]);
            }
        }

        return new Sprite($pose['width'], $pose['height'], $image);
    }

    /**
     * A fully transparent true-colour image that keeps its alpha channel.
     *
     * @param  int  $width  image width in px
     * @param  int  $height  image height in px
     * @return GdImage
     */
    private function transparentImage(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));

        return $image;
    }
}
