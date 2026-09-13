<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A rebalance plan an admin adopted, and how far through it they are.
 *
 * Recalculating on the Rebalance page produces a draft that is not stored:
 * it is a search result and the next search may differ. Adopting one writes
 * a row here, and the moves are then ticked off against it — which is what
 * lets the work survive the reloads it inevitably spans, since every move
 * costs a browser round trip to Anthropic.
 */
class RebalancePlan extends Model
{
    /**
     * Everything on a plan is written at once when it is adopted, and only
     * `applied_indexes` changes afterwards.
     *
     * @var list<string>
     */
    protected $fillable = [
        'adopted_by',
        'range',
        'extra_accounts',
        'moves',
        'accounts',
        'summary',
        'capacity',
        'applied_indexes',
    ];

    /**
     * The admin who adopted this plan, or null once their account is gone.
     *
     * @return BelongsTo<User, $this>
     */
    public function adoptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adopted_by');
    }

    /**
     * Cast the stored plan back into the arrays the page renders.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moves' => 'array',
            'accounts' => 'array',
            'summary' => 'array',
            'capacity' => 'array',
            'applied_indexes' => 'array',
            'extra_accounts' => 'integer',
        ];
    }
}
