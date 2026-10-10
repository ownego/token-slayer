<?php

namespace App\Services\CustomCharacter;

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;

/**
 * Sanity checks on poses that were already located: none may touch the sheet
 * edge (a sign of cropping), and the idle and walk poses must be drawn at a
 * similar height so one shared scale suits them all. Attack and death poses
 * are exempt from the size check because a raised sword or a fallen body
 * legitimately changes their height.
 */
class PoseSheetValidator
{
    /**
     * Rows whose pose heights must agree with each other.
     *
     * @var array<int, string>
     */
    private const array SIZED_ROWS = ['idle', 'walk'];

    /**
     * Throws when the located poses cannot be pixelated together.
     *
     * @param  array<string, array<int, PoseBox>>  $poses  located poses keyed by row name
     * @param  int  $sheetWidth  sheet width in px
     * @param  int  $sheetHeight  sheet height in px
     * @return void
     *
     * @throws InvalidPoseSheetException when a pose is cropped or the sizes disagree
     */
    public function assertUsable(array $poses, int $sheetWidth, int $sheetHeight): void
    {
        foreach ($poses as $name => $boxes) {
            foreach ($boxes as $index => $box) {
                if ($this->touchesEdge($box, $sheetWidth, $sheetHeight)) {
                    throw new InvalidPoseSheetException(
                        PoseSheetProblem::PoseTouchesEdge,
                        sprintf('Row "%s", pose %d.', $name, $index + 1),
                    );
                }
            }
        }

        $this->assertSizesAgree($poses);
    }

    /**
     * Whether the box reaches any side of the sheet.
     *
     * @param  PoseBox  $box  the pose's bounding box
     * @param  int  $sheetWidth  sheet width in px
     * @param  int  $sheetHeight  sheet height in px
     * @return bool
     */
    private function touchesEdge(PoseBox $box, int $sheetWidth, int $sheetHeight): bool
    {
        return $box->left <= 0
            || $box->top <= 0
            || $box->left + $box->width >= $sheetWidth
            || $box->top + $box->height >= $sheetHeight;
    }

    /**
     * Throws when an idle or walk pose is further from the median height than allowed.
     *
     * @param  array<string, array<int, PoseBox>>  $poses  located poses keyed by row name
     * @return void
     *
     * @throws InvalidPoseSheetException when a pose's height strays from the median
     */
    private function assertSizesAgree(array $poses): void
    {
        $heights = [];

        foreach (self::SIZED_ROWS as $name) {
            foreach ($poses[$name] ?? [] as $box) {
                $heights[] = $box->height;
            }
        }

        if ($heights === []) {
            return;
        }

        sort($heights);
        $median = $heights[intdiv(count($heights), 2)];
        $tolerance = (float) config('token_slayer.custom_character.size_tolerance');

        foreach ($heights as $height) {
            if (abs($height - $median) / $median > $tolerance) {
                throw new InvalidPoseSheetException(PoseSheetProblem::PoseSizeMismatch);
            }
        }
    }
}
