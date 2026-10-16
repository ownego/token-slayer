<?php

namespace App\Services\CustomCharacter;

use App\Enums\PoseSheetProblem;
use App\Exceptions\InvalidPoseSheetException;

/**
 * Finds the poses on a keyed sheet from the empty gaps between them: gaps
 * between rows split the sheet into bands, gaps inside a band split it into
 * poses. Requires the configured number of rows, each with an allowed number
 * of poses, and names the rows in order.
 */
class PoseSheetLocator
{
    /**
     * Ink pixels a scan line needs to count as occupied; ignores stray specks.
     *
     * @var int
     */
    private const int MIN_INK_PER_LINE = 3;

    /**
     * Ink pixels a column needs inside a band to count as occupied.
     *
     * @var int
     */
    private const int MIN_INK_PER_COLUMN = 2;

    /**
     * Blocks smaller than this share of the sheet's side are treated as noise, not a row or pose.
     *
     * @var float
     */
    private const float MIN_BLOCK_SHARE = 0.03;

    /**
     * Empty gap, as a share of the sheet's side, that separates two rows or poses.
     *
     * @var float
     */
    private const float GAP_SHARE = 0.005;

    /**
     * Locates the poses row by row.
     *
     * @param  KeyedSheet  $sheet  the sheet with its background removed
     * @return array<string, array<int, PoseBox>> poses left to right, keyed by row name
     *
     * @throws InvalidPoseSheetException when the row count or a row's pose count is wrong
     */
    public function locate(KeyedSheet $sheet): array
    {
        $rules = config('token_slayer.custom_character.rows');
        $bands = $this->rowBands($sheet);

        if (count($bands) !== count($rules)) {
            throw new InvalidPoseSheetException(
                PoseSheetProblem::WrongRowCount,
                sprintf('Found %d, expected %d.', count($bands), count($rules)),
            );
        }

        $located = [];

        foreach ($rules as $index => $rule) {
            $poses = $this->posesInBand($sheet, $bands[$index]);

            if (count($poses) < $rule['min'] || count($poses) > $rule['max']) {
                throw new InvalidPoseSheetException(
                    PoseSheetProblem::WrongPoseCount,
                    sprintf(
                        'Row %d (%s) has %d, expected %s.',
                        $index + 1,
                        $rule['name'],
                        count($poses),
                        $rule['min'] === $rule['max'] ? $rule['min'] : $rule['min'].'-'.$rule['max'],
                    ),
                );
            }

            $located[$rule['name']] = $poses;
        }

        return $located;
    }

    /**
     * Splits the sheet into horizontal bands of ink separated by empty rows.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @return array<int, array{0: int, 1: int}> [firstRow, lastRow] of each band, top to bottom
     */
    private function rowBands(KeyedSheet $sheet): array
    {
        $counts = array_fill(0, $sheet->height, 0);

        for ($y = 0; $y < $sheet->height; $y++) {
            for ($x = 0; $x < $sheet->width; $x++) {
                $counts[$y] += $sheet->isSolid($x, $y) ? 1 : 0;
            }
        }

        return $this->runs(
            $counts,
            self::MIN_INK_PER_LINE,
            max(2, (int) round($sheet->height * self::GAP_SHARE)),
            (int) round($sheet->height * self::MIN_BLOCK_SHARE),
        );
    }

    /**
     * Splits one band into poses separated by empty columns and boxes each.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  array{0: int, 1: int}  $band  [firstRow, lastRow] of the band
     * @return array<int, PoseBox> poses left to right
     */
    private function posesInBand(KeyedSheet $sheet, array $band): array
    {
        [$firstRow, $lastRow] = $band;
        $counts = array_fill(0, $sheet->width, 0);

        for ($x = 0; $x < $sheet->width; $x++) {
            for ($y = $firstRow; $y <= $lastRow; $y++) {
                $counts[$x] += $sheet->isSolid($x, $y) ? 1 : 0;
            }
        }

        $spans = $this->runs(
            $counts,
            self::MIN_INK_PER_COLUMN,
            max(2, (int) round($sheet->width * self::GAP_SHARE)),
            (int) round($sheet->width * self::MIN_BLOCK_SHARE),
        );

        return array_map(fn (array $span): PoseBox => $this->boxOf($sheet, $span[0], $span[1], $firstRow, $lastRow), $spans);
    }

    /**
     * Tight bounding box of the ink inside a column span and row range.
     *
     * @param  KeyedSheet  $sheet  the keyed sheet
     * @param  int  $firstColumn  first column of the span
     * @param  int  $lastColumn  last column of the span
     * @param  int  $firstRow  first row of the band
     * @param  int  $lastRow  last row of the band
     * @return PoseBox
     */
    private function boxOf(KeyedSheet $sheet, int $firstColumn, int $lastColumn, int $firstRow, int $lastRow): PoseBox
    {
        $left = PHP_INT_MAX;
        $right = -1;
        $top = PHP_INT_MAX;
        $bottom = -1;

        for ($y = $firstRow; $y <= $lastRow; $y++) {
            for ($x = $firstColumn; $x <= $lastColumn; $x++) {
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

    /**
     * Finds runs of occupied positions in a projection, closing a run once
     * enough empty positions follow it, and dropping runs that are too short.
     *
     * @param  array<int, int>  $counts  ink count at each position
     * @param  int  $minInk  count at or above which a position is occupied
     * @param  int  $gap  consecutive empty positions that end a run
     * @param  int  $minLength  runs shorter than this are noise
     * @return array<int, array{0: int, 1: int}> [first, last] of each run
     */
    private function runs(array $counts, int $minInk, int $gap, int $minLength): array
    {
        $runs = [];
        $start = null;
        $empty = 0;

        foreach ($counts as $position => $count) {
            if ($count >= $minInk) {
                $start ??= $position;
                $empty = 0;

                continue;
            }

            if ($start !== null && ++$empty >= $gap) {
                $runs[] = [$start, $position - $empty];
                $start = null;
                $empty = 0;
            }
        }

        if ($start !== null) {
            $runs[] = [$start, count($counts) - 1 - $empty];
        }

        return array_values(array_filter($runs, fn (array $run): bool => $run[1] - $run[0] + 1 >= $minLength));
    }
}
