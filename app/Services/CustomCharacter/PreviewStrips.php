<?php

namespace App\Services\CustomCharacter;

/**
 * Describes a fighter version's four strips for a preview: where each is served
 * from, its frame count and rate, and the name the player sees for it ("Defeated"
 * for the strip stored as `death`).
 */
class PreviewStrips
{
    /**
     * The name the player sees for each strip.
     *
     * @var array<string, string>
     */
    private const array LABELS = ['idle' => 'Idle', 'walk' => 'Walk', 'attack' => 'Attack', 'death' => 'Defeated'];

    /**
     * Create the describer.
     *
     * @param  CustomCharacterStore  $store  knows where the strips are served from
     * @return void
     */
    public function __construct(private readonly CustomCharacterStore $store) {}

    /**
     * Describes the strips of one version.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @param  array<string, array{frames: int, rate: int}>  $animations  the version's animation table
     * @param  bool  $staged  whether the version is a staged draft
     * @return list<array{name: string, label: string, url: string, frames: int, rate: int}>
     */
    public function execute(int $userId, string $hash, array $animations, bool $staged = false): array
    {
        $urls = $this->store->stripUrls($userId, $hash, $staged);

        return array_map(fn (string $name): array => [
            'name' => $name,
            'label' => self::LABELS[$name],
            'url' => $urls[$name],
            'frames' => $animations[$name]['frames'],
            'rate' => $animations[$name]['rate'],
        ], CustomCharacterStore::ANIMATIONS);
    }
}
