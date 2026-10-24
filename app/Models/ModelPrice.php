<?php

namespace App\Models;

use Database\Factories\ModelPriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One model's list price from a given day on, in USD per million tokens.
 * Tokens times a per-MTok price is exactly micro-dollars, which is the unit
 * events store their cost in.
 */
class ModelPrice extends Model
{
    /** @use HasFactory<ModelPriceFactory> */
    use HasFactory;

    /**
     * Largest per-MTok price the `decimal(12,6)` columns can hold. A feed
     * value above it is refused before it reaches an insert.
     *
     * @var float
     */
    public const float MAX_PRICE_PER_MTOK = 999999.999999;

    /**
     * Trailing date stamp some vendors append to a model id
     * (`claude-haiku-4-5-20251001`).
     *
     * @var string
     */
    private const string DATED_SUFFIX_PATTERN = '/-\d{8}\z/';

    /**
     * Columns written by the price sync and the history seeder.
     *
     * @var list<string>
     */
    protected $fillable = [
        'model',
        'provider',
        'effective_from',
        'input_per_mtok',
        'output_per_mtok',
        'cache_read_per_mtok',
        'cache_write_5m_per_mtok',
        'cache_write_1h_per_mtok',
        'source_sha256',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'input_per_mtok' => 'float',
            'output_per_mtok' => 'float',
            'cache_read_per_mtok' => 'float',
            'cache_write_5m_per_mtok' => 'float',
            'cache_write_1h_per_mtok' => 'float',
        ];
    }

    /**
     * The ids a price may be stored under for a model id seen on an event:
     * the id itself, then the id without a trailing date stamp.
     *
     * @param  string  $modelId  the raw model id from an event
     * @return list<string>
     */
    public static function candidateIds(string $modelId): array
    {
        $undated = preg_replace(self::DATED_SUFFIX_PATTERN, '', $modelId);

        if ($undated === null || $undated === $modelId || $undated === '') {
            return [$modelId];
        }

        return [$modelId, $undated];
    }
}
