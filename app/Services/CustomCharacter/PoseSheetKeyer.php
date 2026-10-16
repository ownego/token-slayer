<?php

namespace App\Services\CustomCharacter;

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;
use GdImage;

/**
 * Separates character ink from background on a pose sheet. The background
 * must be solid magenta or transparent; it is recognised from the sheet's
 * outer border, so a sheet on any other background is refused rather than
 * guessed at.
 */
class PoseSheetKeyer
{
    /**
     * Euclidean RGB distance from #FF00FF above which a pixel counts as ink.
     *
     * @var int
     */
    private const int MAGENTA_TOLERANCE = 100;

    /**
     * GD alpha (0 opaque .. 127 transparent) below which a pixel counts as opaque.
     *
     * @var int
     */
    private const int OPAQUE_BELOW = 64;

    /**
     * Share of border pixels that must match the background for it to be recognised.
     *
     * @var float
     */
    private const float BORDER_MATCH = 0.9;

    /**
     * Removes the background and returns the ink mask.
     *
     * @param  GdImage  $image  the decoded sheet
     * @return KeyedSheet the bitmap with its ink mask
     *
     * @throws InvalidPoseSheetException when the background is neither magenta nor transparent
     */
    public function key(GdImage $image): KeyedSheet
    {
        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $magenta = $this->borderIsMagenta($image, $width, $height);

        if (! $magenta && ! $this->borderIsTransparent($image, $width, $height)) {
            throw new InvalidPoseSheetException(PoseSheetProblem::BackgroundNotSupported);
        }

        $solid = str_repeat("\0", $width * $height);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($this->isInk(imagecolorat($image, $x, $y), $magenta)) {
                    $solid[$y * $width + $x] = "\1";
                }
            }
        }

        return new KeyedSheet($image, $width, $height, $magenta ? $this->erode($solid, $width, $height) : $solid);
    }

    /**
     * Whether a pixel is ink under the recognised background kind.
     *
     * @param  int  $argb  the pixel as returned by imagecolorat()
     * @param  bool  $magenta  true when the background is magenta, false when transparent
     * @return bool
     */
    private function isInk(int $argb, bool $magenta): bool
    {
        if ((($argb >> 24) & 0x7F) >= self::OPAQUE_BELOW) {
            return false;
        }

        if (! $magenta) {
            return true;
        }

        return $this->magentaDistance($argb) > self::MAGENTA_TOLERANCE;
    }

    /**
     * Euclidean RGB distance of a pixel from #FF00FF.
     *
     * @param  int  $argb  the pixel as returned by imagecolorat()
     * @return float
     */
    private function magentaDistance(int $argb): float
    {
        $red = ($argb >> 16) & 0xFF;
        $green = ($argb >> 8) & 0xFF;
        $blue = $argb & 0xFF;

        return sqrt((255 - $red) ** 2 + $green ** 2 + (255 - $blue) ** 2);
    }

    /**
     * Whether nearly every border pixel is opaque magenta.
     *
     * @param  GdImage  $image  the sheet
     * @param  int  $width  sheet width
     * @param  int  $height  sheet height
     * @return bool
     */
    private function borderIsMagenta(GdImage $image, int $width, int $height): bool
    {
        return $this->borderShare($image, $width, $height, function (int $argb): bool {
            return (($argb >> 24) & 0x7F) < self::OPAQUE_BELOW
                && $this->magentaDistance($argb) <= self::MAGENTA_TOLERANCE;
        }) >= self::BORDER_MATCH;
    }

    /**
     * Whether nearly every border pixel is transparent.
     *
     * @param  GdImage  $image  the sheet
     * @param  int  $width  sheet width
     * @param  int  $height  sheet height
     * @return bool
     */
    private function borderIsTransparent(GdImage $image, int $width, int $height): bool
    {
        return $this->borderShare($image, $width, $height, function (int $argb): bool {
            return (($argb >> 24) & 0x7F) >= self::OPAQUE_BELOW;
        }) >= self::BORDER_MATCH;
    }

    /**
     * Share of the outer border's pixels that satisfy the test.
     *
     * @param  GdImage  $image  the sheet
     * @param  int  $width  sheet width
     * @param  int  $height  sheet height
     * @param  callable(int): bool  $matches  test applied to each border pixel's imagecolorat() value
     * @return float 0.0 .. 1.0
     */
    private function borderShare(GdImage $image, int $width, int $height, callable $matches): float
    {
        $hits = 0;
        $total = 0;

        for ($x = 0; $x < $width; $x++) {
            foreach ([0, $height - 1] as $y) {
                $total++;
                $hits += $matches(imagecolorat($image, $x, $y)) ? 1 : 0;
            }
        }

        for ($y = 1; $y < $height - 1; $y++) {
            foreach ([0, $width - 1] as $x) {
                $total++;
                $hits += $matches(imagecolorat($image, $x, $y)) ? 1 : 0;
            }
        }

        return $total === 0 ? 0.0 : $hits / $total;
    }

    /**
     * Peels one pixel off every ink edge (4-neighbourhood), removing the
     * magenta-tinted fringe that anti-aliasing leaves around a pose.
     *
     * @param  string  $solid  the ink mask
     * @param  int  $width  sheet width
     * @param  int  $height  sheet height
     * @return string the eroded mask
     */
    private function erode(string $solid, int $width, int $height): string
    {
        $eroded = str_repeat("\0", $width * $height);

        for ($y = 1; $y < $height - 1; $y++) {
            for ($x = 1; $x < $width - 1; $x++) {
                $index = $y * $width + $x;

                if ($solid[$index] === "\1"
                    && $solid[$index - 1] === "\1"
                    && $solid[$index + 1] === "\1"
                    && $solid[$index - $width] === "\1"
                    && $solid[$index + $width] === "\1") {
                    $eroded[$index] = "\1";
                }
            }
        }

        return $eroded;
    }
}
