<?php

namespace App\Services\Accounts;

use Illuminate\Support\Carbon;

/**
 * The per-model quota limits inside a stored usage-probe response.
 *
 * Read out of the response's `limits[]` array rather than its top-level keys.
 * Those top-level keys look like the obvious source and are not: verified
 * against a live account on 2026-09-07, `seven_day_opus`, `seven_day_sonnet`
 * and every codename (`tangelo`, `cinder_cove`, `copper_kite`, `juniper_tide`
 * …) come back literally `null`, and the one that is populated,
 * `nimbus_quill`, is an empty placeholder — utilization 0, no reset, no
 * dollars, and no way to tell an admin which model it belongs to.
 *
 * `limits[]` is the source that answers the question. Each entry carries a
 * percent, a severity and a reset, and a model-scoped one names its model
 * outright: `scope.model.display_name` reads "Fable". Nothing here has to
 * know model names or invent labels for codenames, so a model released after
 * this shipped appears under its real name with no migration and no deploy.
 */
final class ModelQuotaLimits
{
    /**
     * The model-scoped limits in a probe response, fullest first.
     *
     * Entries with no scope are the account's own session and weekly figures,
     * already surfaced as their own gauges; repeating them would read as more
     * models. An entry scoped to something other than a model, or to a model
     * with no display name, is skipped rather than guessed at — a label put
     * on the wrong thing is a wrong answer, not a missing one.
     *
     * @param  mixed  $raw  the stored probe response, whatever shape it is in
     * @return array<int, array{model: string, percent: int, severity: ?string, resets_at: ?Carbon}>
     */
    public static function from(mixed $raw): array
    {
        $limits = is_array($raw) ? ($raw['limits'] ?? null) : null;

        if (! is_array($limits)) {
            return [];
        }

        $scoped = [];

        foreach ($limits as $limit) {
            if (! is_array($limit)) {
                continue;
            }

            $model = $limit['scope']['model']['display_name'] ?? null;

            if (! is_string($model) || trim($model) === '') {
                continue;
            }

            $resetsAt = $limit['resets_at'] ?? null;

            $scoped[] = [
                'model' => $model,
                'percent' => (int) round((float) ($limit['percent'] ?? 0)),
                'severity' => is_string($limit['severity'] ?? null) ? $limit['severity'] : null,
                'resets_at' => is_string($resetsAt) ? Carbon::parse($resetsAt) : null,
            ];
        }

        usort($scoped, fn (array $a, array $b): int => $b['percent'] <=> $a['percent']);

        return $scoped;
    }
}
