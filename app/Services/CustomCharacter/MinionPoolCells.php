<?php

namespace App\Services\CustomCharacter;

use App\Models\CustomMinion;
use App\Models\CustomMinionDraft;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The Minions tab's pool as the editor draws it: one cell per default look, one per slot
 * (a minion, an empty slot, or the preview waiting to take one) and, when the set filled up
 * under a preview, an extra cell for that preview. Everything the view needs comes out of
 * here, ready to print, so the blade holds no query and no rule: `MinionPool` decides what
 * the swarm summons, this decides what the tab shows about it.
 *
 * The server does not hold the strips of the five default looks (they ship in the client's
 * `MINION_TYPES`), so a default's `idle` names its key and the client looks the strip up;
 * a custom minion's `idle` is the spec to draw it from.
 *
 * Everything is read off the relations the player is loaded with (`customMinions`,
 * `customMinionDraft`), so the caller decides when they are fresh and the cells add no query.
 */
class MinionPoolCells
{
    /**
     * What the player calls each default look. A key with no entry is named from the key.
     *
     * @var array<string, string>
     */
    private const array LABELS = [
        'demon-a' => 'Demon',
        'blood-monster-a' => 'Blood monster',
        'smw-goomba' => 'Goomba',
        'smw-babybowser' => 'Baby Bowser',
        'smw-bowser' => 'Bowser',
    ];

    /**
     * Create the builder.
     *
     * @param  CustomMinionSlots  $slots  the one predicate for which slot is free
     * @param  CustomMinionStore  $store  knows where a staged preview's strips are served from
     * @return void
     */
    public function __construct(
        private readonly CustomMinionSlots $slots,
        private readonly CustomMinionStore $store,
    ) {}

    /**
     * The cells of the player's pool.
     *
     * @param  User  $user  the player, with `customMinions` and `customMinionDraft` loaded
     * @return array{
     *     defaults: list<array{look: string, key: string, label: string, ticked: bool, idle: array{kind: string, key: string}}>,
     *     slots: list<array{look: string, slot: int, name: ?string, ticked: bool, idle: ?array<string, mixed>, body_px: ?int, source: ?string, draft: ?array<string, mixed>}>,
     *     overflowDraft: ?array<string, mixed>,
     *     nothingTicked: bool,
     *     used: int,
     *     max: int
     * }
     */
    public function get(User $user): array
    {
        $off = $user->minion_defaults_off ?? [];
        $minions = $user->customMinions->keyBy('slot');
        $max = (int) config('token_slayer.custom_character.minions_max');
        $draft = $user->customMinionDraft;
        $target = $this->targetSlot($user, $draft, $max);
        $preview = $draft === null ? null : $this->previewOf($user, $draft, $minions->get($target), $target);

        $defaults = array_map(fn (string $key): array => [
            'look' => "default:{$key}",
            'key' => $key,
            'label' => self::LABELS[$key] ?? Str::headline($key),
            'ticked' => ! in_array($key, $off, true),
            'idle' => ['kind' => 'default', 'key' => $key],
        ], array_values(config('token_slayer.custom_character.default_minions')));

        $slots = [];

        for ($slot = 1; $slot <= $max; $slot++) {
            $slots[] = $this->slotCell($slot, $minions->get($slot), $slot === $target ? $preview : null);
        }

        return [
            'defaults' => $defaults,
            'slots' => $slots,
            'overflowDraft' => $preview !== null && $target === null ? $preview : null,
            'nothingTicked' => $this->nothingTicked($defaults, $minions),
            'used' => $minions->count(),
            'max' => $max,
        ];
    }

    /**
     * The slot a preview sits in: the slot it will take over, or, for a new minion, the
     * lowest slot that is free. Null when there is no preview, or when the set filled up
     * under a new one (it then has a cell of its own, after the slots).
     *
     * @param  User  $user  the player
     * @param  CustomMinionDraft|null  $draft  the player's preview
     * @param  int  $max  how many slots there are
     * @return int|null
     */
    private function targetSlot(User $user, ?CustomMinionDraft $draft, int $max): ?int
    {
        if ($draft === null) {
            return null;
        }

        if ($draft->slot !== null && $draft->slot >= 1 && $draft->slot <= $max) {
            return $draft->slot;
        }

        return $this->slots->firstFree($user);
    }

    /**
     * One slot's cell.
     *
     * @param  int  $slot  the slot
     * @param  CustomMinion|null  $minion  the minion in it, if any
     * @param  array<string, mixed>|null  $preview  the preview that sits in it, if any
     * @return array{look: string, slot: int, name: ?string, ticked: bool, idle: ?array<string, mixed>, body_px: ?int, source: ?string, draft: ?array<string, mixed>}
     */
    private function slotCell(int $slot, ?CustomMinion $minion, ?array $preview): array
    {
        return [
            'look' => "slot:{$slot}",
            'slot' => $slot,
            'name' => $minion === null ? null : $this->nameOf($minion),
            'ticked' => $minion?->enabled ?? false,
            'idle' => $minion === null ? null : ['kind' => 'custom', ...$minion->broadcastState()],
            'body_px' => $minion?->body_px,
            'source' => $minion?->source?->value,
            'draft' => $preview,
        ];
    }

    /**
     * What a preview needs to be shown and acted on: its hash, the slot it is aimed at, the name
     * it will carry and the spec to draw it from (its strips are in the staged area).
     *
     * @param  User  $user  the player
     * @param  CustomMinionDraft  $draft  the preview
     * @param  CustomMinion|null  $replaced  the minion it will take over, if it takes one over
     * @param  int|null  $target  the slot it sits in, null when the set is full
     * @return array{hash: string, slot: ?int, target: ?int, replaces: ?string, name: string, source: ?string, body_px: int, spec: array<string, mixed>}
     */
    private function previewOf(User $user, CustomMinionDraft $draft, ?CustomMinion $replaced, ?int $target): array
    {
        return [
            'hash' => $draft->version_hash,
            'slot' => $draft->slot,
            'target' => $target,
            'replaces' => $replaced === null ? null : $this->nameOf($replaced),
            'name' => $replaced !== null ? $this->nameOf($replaced) : ($draft->name ?? 'Minion '.($target ?? '')),
            'source' => $draft->source?->value,
            'body_px' => $draft->body_px,
            'spec' => [
                'kind' => 'custom',
                'version' => $draft->version_hash,
                'url_base' => $this->store->baseUrl($user->id, $draft->version_hash, staged: true),
                'animations' => $draft->animations,
                'body_px' => $draft->body_px,
            ],
        ];
    }

    /**
     * What a minion is called: its name follows its slot, and a row with none is named after it.
     *
     * @param  CustomMinion  $minion  the minion
     * @return string
     */
    private function nameOf(CustomMinion $minion): string
    {
        return $minion->name ?? "Minion {$minion->slot}";
    }

    /**
     * Whether nothing is ticked, so the swarm falls back to the defaults.
     *
     * @param  array<int, array{ticked: bool}>  $defaults  the default cells
     * @param  Collection<int, CustomMinion>  $minions  the player's minions
     * @return bool
     */
    private function nothingTicked(array $defaults, Collection $minions): bool
    {
        return ! collect($defaults)->contains('ticked', true) && ! $minions->contains('enabled', true);
    }
}
