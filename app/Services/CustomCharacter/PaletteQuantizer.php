<?php

namespace App\Services\CustomCharacter;

/**
 * Median-cut colour quantiser. It always splits the box with the widest
 * colour range rather than the most pixels, so a rare but distinct colour
 * (a dark pupil among thousands of fur pixels) keeps a palette entry of its
 * own instead of being averaged away.
 */
class PaletteQuantizer
{
    /**
     * Maps every colour to one of at most $maxColors palette colours.
     *
     * @param  array<int, int>  $weights  pixel count per 0xRRGGBB colour
     * @param  int  $maxColors  palette size limit
     * @return array<int, int> original 0xRRGGBB => palette 0xRRGGBB
     */
    public function quantize(array $weights, int $maxColors): array
    {
        if ($weights === []) {
            return [];
        }

        $boxes = [array_keys($weights)];

        while (count($boxes) < $maxColors) {
            $target = $this->widestBox($boxes);

            if ($target === null) {
                break;
            }

            [$lower, $upper] = $this->split($boxes[$target[0]], $target[1], $weights);
            array_splice($boxes, $target[0], 1, [$lower, $upper]);
        }

        $map = [];

        foreach ($boxes as $box) {
            $average = $this->average($box, $weights);

            foreach ($box as $colour) {
                $map[$colour] = $average;
            }
        }

        return $map;
    }

    /**
     * Picks the splittable box whose widest channel range is largest.
     *
     * @param  array<int, array<int, int>>  $boxes  colour groups
     * @return ?array{0: int, 1: int} [box index, channel shift] or null when no box can be split
     */
    private function widestBox(array $boxes): ?array
    {
        $best = null;
        $bestRange = 0;

        foreach ($boxes as $index => $box) {
            if (count($box) < 2) {
                continue;
            }

            foreach ([16, 8, 0] as $shift) {
                $values = array_map(fn (int $colour): int => ($colour >> $shift) & 0xFF, $box);
                $range = max($values) - min($values);

                if ($range > $bestRange) {
                    $bestRange = $range;
                    $best = [$index, $shift];
                }
            }
        }

        return $best;
    }

    /**
     * Splits a box at the weighted median of one channel.
     *
     * @param  array<int, int>  $box  the colours to split
     * @param  int  $shift  bit shift of the channel to split on (16 red, 8 green, 0 blue)
     * @param  array<int, int>  $weights  pixel count per colour
     * @return array{0: array<int, int>, 1: array<int, int>} the lower and upper halves, both non-empty
     */
    private function split(array $box, int $shift, array $weights): array
    {
        usort($box, fn (int $a, int $b): int => (($a >> $shift) & 0xFF) <=> (($b >> $shift) & 0xFF));

        $half = array_sum(array_map(fn (int $colour): int => $weights[$colour], $box)) / 2;
        $running = 0;
        $cut = 1;

        foreach ($box as $index => $colour) {
            $running += $weights[$colour];

            if ($running >= $half) {
                $cut = min(max($index + 1, 1), count($box) - 1);
                break;
            }
        }

        return [array_slice($box, 0, $cut), array_slice($box, $cut)];
    }

    /**
     * Weighted average colour of a box.
     *
     * @param  array<int, int>  $box  the colours to average
     * @param  array<int, int>  $weights  pixel count per colour
     * @return int the average as 0xRRGGBB
     */
    private function average(array $box, array $weights): int
    {
        $sum = [0, 0, 0];
        $total = 0;

        foreach ($box as $colour) {
            $weight = $weights[$colour];
            $sum[0] += (($colour >> 16) & 0xFF) * $weight;
            $sum[1] += (($colour >> 8) & 0xFF) * $weight;
            $sum[2] += ($colour & 0xFF) * $weight;
            $total += $weight;
        }

        return ((int) round($sum[0] / $total) << 16) | ((int) round($sum[1] / $total) << 8) | (int) round($sum[2] / $total);
    }
}
