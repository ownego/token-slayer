<?php

namespace App\Support;

/**
 * Keeps a ranked list short: the first rows as they are, the rest folded into
 * one "other" row. The fighter sheet's By-model block sits above Clawd in the
 * sidebar, and a long tail of model ids would push Clawd out of view.
 */
final class TopRows
{
    /**
     * The first `$keep` rows, then one `model => 'other'` row summing the rest.
     *
     * @param  array<int, array{model: string, family: ?string, damage: int, share: float}>  $rows  already ranked, biggest first
     * @param  int  $keep
     * @return array<int, array{model: string, family: ?string, damage: int, share: float}>
     */
    public static function fold(array $rows, int $keep): array
    {
        if (count($rows) <= $keep + 1) {
            return $rows;
        }

        $rest = array_slice($rows, $keep);

        return [
            ...array_slice($rows, 0, $keep),
            [
                'model' => 'other',
                'family' => null,
                'damage' => array_sum(array_column($rest, 'damage')),
                'share' => round(array_sum(array_column($rest, 'share')), 4),
            ],
        ];
    }
}
