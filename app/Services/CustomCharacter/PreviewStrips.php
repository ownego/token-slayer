<?php

namespace App\Services\CustomCharacter;

use App\Models\User;

/**
 * Describes a fighter version for a preview: its four strips (where each is served
 * from, its frame count and rate, and the name the player sees for it, the
 * sheet's own words: "Death" for the strip stored as `death`) and the payload the sheet plays it from.
 */
class PreviewStrips
{
    /**
     * The name the player sees for each strip.
     *
     * @var array<string, string>
     */
    private const array LABELS = ['idle' => 'Idle', 'walk' => 'Walk', 'attack' => 'Attack', 'death' => 'Death'];

    /**
     * Create the describer.
     *
     * @param  CustomCharacterStore  $store  knows where the fighter's strips are served from
     * @param  CustomMinionStore  $minionStore  knows where the minions' strips are served from
     * @return void
     */
    public function __construct(
        private readonly CustomCharacterStore $store,
        private readonly CustomMinionStore $minionStore,
    ) {}

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

    /**
     * The version as the `custom_character` payload the sheet's sprite helpers play from.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @param  array<string, array{frames: int, rate: int}>  $animations  the version's animation table
     * @param  bool  $staged  whether the version is a staged draft
     * @param  string  $name  what the fighter is called
     * @return array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, name: string}
     */
    public function spec(int $userId, string $hash, array $animations, bool $staged, string $name): array
    {
        return [
            'version' => $hash,
            'url_base' => $this->store->baseUrl($userId, $hash, $staged),
            'animations' => $animations,
            'name' => $name,
        ];
    }

    /**
     * The player's pending draft as the sheet's stage takes it: its hash and its
     * staged spec, named like the player's fighter (or the default name before there
     * is one). Null when nothing is waiting.
     *
     * @param  User  $user  the owner
     * @return array{hash: string, spec: array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, name: string}}|null
     */
    public function draftPayload(User $user): ?array
    {
        $draft = $user->customCharacterDraft()->first();

        if ($draft === null) {
            return null;
        }

        $name = $user->customCharacter()->value('name') ?? (string) config('token_slayer.custom_character.default_name');

        return [
            'hash' => $draft->version_hash,
            'spec' => $this->spec($user->id, $draft->version_hash, $draft->animations, staged: true, name: $name),
        ];
    }

    /**
     * The player's pending minion draft as the sheet's stage takes it: its hash and its
     * staged spec (version, where the strips are served from, animations, body height).
     * Null when nothing is waiting.
     *
     * @param  User  $user  the owner
     * @return array{hash: string, spec: array{version: string, url_base: string, animations: array<string, array{frames: int, rate: int}>, body_px: int}}|null
     */
    public function minionDraftPayload(User $user): ?array
    {
        $draft = $user->customMinionDraft()->first();

        if ($draft === null) {
            return null;
        }

        return [
            'hash' => $draft->version_hash,
            'spec' => [
                'version' => $draft->version_hash,
                'url_base' => $this->minionStore->baseUrl($user->id, $draft->version_hash, staged: true),
                'animations' => $draft->animations,
                'body_px' => $draft->body_px,
            ],
        ];
    }
}
