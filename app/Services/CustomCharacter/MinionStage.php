<?php

namespace App\Services\CustomCharacter;

use App\Enums\MinionStageMode;

/**
 * What the Minions tab's stage shows for the cell the player picked: which of the five
 * panels goes with it, the cell's look key, its name, the preview it holds (if any), the
 * spec to draw it from, whether it is ticked into the pool as the player now sees it, and
 * its slot. Built by `MinionPoolCells::stageOf()`; the view only reads it.
 */
final readonly class MinionStage
{
    /**
     * Create the stage.
     *
     * @param  MinionStageMode  $mode  which panel goes with the cell
     * @param  string  $look  the cell: `default:<key>`, `slot:<n>` or `overflow`
     * @param  string|null  $name  what the cell is called, null for an empty slot
     * @param  array<string, mixed>|null  $draft  the preview the cell holds, if any
     * @param  array<string, mixed>|null  $spec  what to draw the look from, null for an empty slot
     * @param  bool  $ticked  whether the look is in the pool
     * @param  int|null  $slot  the slot, null for a default look and the overflow preview
     * @return void
     */
    public function __construct(
        public MinionStageMode $mode,
        public string $look,
        public ?string $name,
        public ?array $draft,
        public ?array $spec,
        public bool $ticked,
        public ?int $slot,
    ) {}

    /**
     * Whether the stage holds a preview (in a slot, or the overflow one).
     *
     * @return bool
     */
    public function isPreview(): bool
    {
        return $this->mode === MinionStageMode::Draft || $this->mode === MinionStageMode::Overflow;
    }

    /**
     * Whether the stage holds one of the player's own minions or a default look, which a
     * tick can put in or out of the pool.
     *
     * @return bool
     */
    public function isTickable(): bool
    {
        return $this->mode === MinionStageMode::Default || $this->mode === MinionStageMode::Minion;
    }
}
