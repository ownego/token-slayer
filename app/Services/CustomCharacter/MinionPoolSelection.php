<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * What a player has ticked into their minion pool, read off `users.minion_pool` and held
 * as a value. Only what the player left out is stored: the default looks (`defaults_off`)
 * and the slots of custom minions (`slots_off`), so a player who never touched a tick has
 * every look in the pool, and a minion added later is in it too. `rev` is the counter that
 * orders one pool against another on the client.
 *
 * Reading is forgiving, so a stored value never breaks the pool: a default key that is
 * no longer in `custom_character.default_minions` is dropped, a repeated entry is kept once
 * and the lists come out in a fixed order (the config's, and slot order). Changing the
 * selection never mutates it: each `with…()` returns a new one, and `MinionPoolWriter` is the
 * only thing that stores one.
 */
final readonly class MinionPoolSelection
{
    /**
     * The pool revision, 0 until the first change is saved.
     *
     * @var int
     */
    public int $rev;

    /**
     * The default looks that are not in the pool, in the config's order.
     *
     * @var list<string>
     */
    public array $defaultsOff;

    /**
     * The slots whose custom minion is not in the pool, lowest first.
     *
     * @var list<int>
     */
    public array $slotsOff;

    /**
     * Create the selection, normalising what it is given.
     *
     * @param  int  $rev  the pool revision
     * @param  array<int, mixed>  $defaultsOff  the default keys left out; keys that are not defaults are dropped
     * @param  array<int, mixed>  $slotsOff  the slots left out
     * @return void
     */
    public function __construct(int $rev = 0, array $defaultsOff = [], array $slotsOff = [])
    {
        $this->rev = max(0, $rev);
        $this->defaultsOff = array_values(array_filter(self::defaultKeys(), fn (string $key): bool => in_array($key, $defaultsOff, true)));
        $this->slotsOff = collect($slotsOff)->filter(fn (mixed $slot): bool => is_int($slot) || (is_string($slot) && ctype_digit($slot)))
            ->map(fn (mixed $slot): int => (int) $slot)
            ->filter(fn (int $slot): bool => $slot >= 1)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The selection a player's `minion_pool` column holds; the untouched selection for null.
     *
     * @param  User  $user  the player
     * @return self
     */
    public static function of(User $user): self
    {
        return self::fromColumn($user->minion_pool);
    }

    /**
     * The selection a decoded `minion_pool` value holds. Anything that is not the stored
     * shape reads as the untouched selection.
     *
     * @param  mixed  $column  the decoded column
     * @return self
     */
    public static function fromColumn(mixed $column): self
    {
        if (! is_array($column)) {
            return new self;
        }

        $defaultsOff = $column['defaults_off'] ?? [];
        $slotsOff = $column['slots_off'] ?? [];

        return new self(
            (int) ($column['rev'] ?? 0),
            is_array($defaultsOff) ? $defaultsOff : [],
            is_array($slotsOff) ? $slotsOff : [],
        );
    }

    /**
     * The five default looks, in the order the config lists them.
     *
     * @return list<string>
     */
    public static function defaultKeys(): array
    {
        return array_values(config('token_slayer.custom_character.default_minions'));
    }

    /**
     * The value to store in `users.minion_pool`.
     *
     * @return array{rev: int, defaults_off: list<string>, slots_off: list<int>}
     */
    public function toColumn(): array
    {
        return ['rev' => $this->rev, 'defaults_off' => $this->defaultsOff, 'slots_off' => $this->slotsOff];
    }

    /**
     * Whether a default look is in the pool.
     *
     * @param  string  $key  the default look's key
     * @return bool
     */
    public function defaultTicked(string $key): bool
    {
        return ! in_array($key, $this->defaultsOff, true);
    }

    /**
     * Whether the custom minion in a slot is in the pool (a slot nobody left out is in it).
     *
     * @param  int  $slot  the slot
     * @return bool
     */
    public function slotTicked(int $slot): bool
    {
        return ! in_array($slot, $this->slotsOff, true);
    }

    /**
     * The same selection with one default look ticked or unticked.
     *
     * @param  string  $key  the default look's key
     * @param  bool  $ticked  whether it should be in the pool
     * @return self
     */
    public function withDefault(string $key, bool $ticked): self
    {
        $off = $ticked ? array_diff($this->defaultsOff, [$key]) : [...$this->defaultsOff, $key];

        return new self($this->rev, $off, $this->slotsOff);
    }

    /**
     * The same selection with the minion in a slot ticked or unticked.
     *
     * @param  int  $slot  the slot
     * @param  bool  $ticked  whether it should be in the pool
     * @return self
     */
    public function withSlot(int $slot, bool $ticked): self
    {
        $off = $ticked ? array_diff($this->slotsOff, [$slot]) : [...$this->slotsOff, $slot];

        return new self($this->rev, $this->defaultsOff, $off);
    }

    /**
     * The same choice under another revision.
     *
     * @param  int  $rev  the revision
     * @return self
     */
    public function withRev(int $rev): self
    {
        return new self($rev, $this->defaultsOff, $this->slotsOff);
    }

    /**
     * The same revision with another choice.
     *
     * @param  array<int, mixed>  $defaultsOff  the default keys left out
     * @param  array<int, mixed>  $slotsOff  the slots left out
     * @return self
     */
    public function withChoice(array $defaultsOff, array $slotsOff): self
    {
        return new self($this->rev, $defaultsOff, $slotsOff);
    }

    /**
     * Whether another selection leaves out the same looks. Only the slots in `$heldSlots`
     * count: a slot nobody holds any more has no minion to leave out, whatever is stored for it.
     *
     * @param  self  $other  the selection to compare with
     * @param  list<int>  $heldSlots  the slots that hold a minion
     * @return bool
     */
    public function sameChoiceAs(self $other, array $heldSlots): bool
    {
        return $this->defaultsOff === $other->defaultsOff
            && array_values(array_intersect($this->slotsOff, $heldSlots)) === array_values(array_intersect($other->slotsOff, $heldSlots));
    }

    /**
     * Whether nothing at all is in the pool, so the swarm falls back to the five defaults.
     *
     * @param  list<int>  $heldSlots  the slots that hold a minion
     * @return bool
     */
    public function isEmpty(array $heldSlots): bool
    {
        return count($this->defaultsOff) === count(self::defaultKeys())
            && array_diff($heldSlots, $this->slotsOff) === [];
    }
}
