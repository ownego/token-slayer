<?php

namespace App\Services\CustomCharacter;

/**
 * Erases small detached marks (motion lines, sparks, impact streaks) that an
 * AI sometimes draws beside a pose. The game already has its own attack
 * effects, so baked-in ones would double up. A blob is removed only when it
 * is not connected to the pose and is tiny next to it; a larger detached
 * part, such as a sword lying beside a fallen body, is kept, and the pose's
 * largest piece is never removed.
 */
class StrayMarkRemover
{
    /**
     * Removes stray marks from every pose and tightens the pose boxes.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  array<string, array<int, PoseBox>>  $poses  located poses keyed by row name
     * @return array{0: KeyedSheet, 1: array<string, array<int, PoseBox>>} the cleaned sheet and the tightened boxes
     */
    public function remove(KeyedSheet $sheet, array $poses): array
    {
        $ratio = (float) config('token_slayer.custom_character.stray_ratio');
        $erased = [];

        foreach ($poses as $boxes) {
            foreach ($boxes as $box) {
                array_push($erased, ...$this->strayPixels($sheet, $box, $ratio));
            }
        }

        $clean = $sheet->cleared($erased);
        $tightened = [];

        foreach ($poses as $name => $boxes) {
            $tightened[$name] = array_map(fn (PoseBox $box): PoseBox => $this->tighten($clean, $box), $boxes);
        }

        return [$clean, $tightened];
    }

    /**
     * Pixels of the blobs in a pose that are small enough to be stray marks.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  PoseBox  $box  the pose's box
     * @param  float  $ratio  blobs under this share of the pose's ink are stray
     * @return array<int, int> row-major pixel indices to erase
     */
    private function strayPixels(KeyedSheet $sheet, PoseBox $box, float $ratio): array
    {
        $blobs = $this->blobsIn($sheet, $box);

        if (count($blobs) < 2) {
            return [];
        }

        $total = array_sum(array_map('count', $blobs));
        $largest = array_key_first(array_filter($blobs, fn (array $blob): bool => count($blob) === max(array_map('count', $blobs))));
        $stray = [];

        foreach ($blobs as $index => $blob) {
            if ($index !== $largest && count($blob) < $ratio * $total) {
                array_push($stray, ...$blob);
            }
        }

        return $stray;
    }

    /**
     * Groups the ink inside a box into 8-connected blobs.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  PoseBox  $box  the pose's box
     * @return array<int, array<int, int>> pixel indices of each blob
     */
    private function blobsIn(KeyedSheet $sheet, PoseBox $box): array
    {
        $seen = [];
        $blobs = [];
        $right = $box->left + $box->width;
        $bottom = $box->top + $box->height;

        for ($y = $box->top; $y < $bottom; $y++) {
            for ($x = $box->left; $x < $right; $x++) {
                $start = $y * $sheet->width + $x;

                if (isset($seen[$start]) || ! $sheet->isSolid($x, $y)) {
                    continue;
                }

                $seen[$start] = true;
                $stack = [[$x, $y]];
                $blob = [];

                while ($stack !== []) {
                    [$cx, $cy] = array_pop($stack);
                    $blob[] = $cy * $sheet->width + $cx;

                    for ($dy = -1; $dy <= 1; $dy++) {
                        for ($dx = -1; $dx <= 1; $dx++) {
                            $nx = $cx + $dx;
                            $ny = $cy + $dy;
                            $index = $ny * $sheet->width + $nx;

                            if ($nx >= $box->left && $nx < $right && $ny >= $box->top && $ny < $bottom
                                && ! isset($seen[$index]) && $sheet->isSolid($nx, $ny)) {
                                $seen[$index] = true;
                                $stack[] = [$nx, $ny];
                            }
                        }
                    }
                }

                $blobs[] = $blob;
            }
        }

        return $blobs;
    }

    /**
     * The tight bounding box of the ink left inside a pose's old box.
     *
     * @param  KeyedSheet  $sheet  the cleaned sheet
     * @param  PoseBox  $box  the pose's previous box
     * @return PoseBox
     */
    private function tighten(KeyedSheet $sheet, PoseBox $box): PoseBox
    {
        $left = PHP_INT_MAX;
        $right = -1;
        $top = PHP_INT_MAX;
        $bottom = -1;

        for ($y = $box->top; $y < $box->top + $box->height; $y++) {
            for ($x = $box->left; $x < $box->left + $box->width; $x++) {
                if ($sheet->isSolid($x, $y)) {
                    $left = min($left, $x);
                    $right = max($right, $x);
                    $top = min($top, $y);
                    $bottom = max($bottom, $y);
                }
            }
        }

        return new PoseBox($left, $top, $right - $left + 1, $bottom - $top + 1);
    }
}
