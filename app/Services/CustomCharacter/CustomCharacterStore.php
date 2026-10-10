<?php

namespace App\Services\CustomCharacter;

use GdImage;
use Illuminate\Support\Facades\Storage;

/**
 * Writes a user's finished strips to the public disk. The folder name carries
 * a hash of the strips' content, so a changed character gets a new URL and
 * cached copies of the old one cannot be served for it; the previous version
 * is deleted once the new one is in place.
 */
class CustomCharacterStore
{
    /**
     * Root folder on the public disk that holds every user's character.
     *
     * @var string
     */
    private const string ROOT = 'custom-characters';

    /**
     * Length of the version hash used in the folder name.
     *
     * @var int
     */
    private const int HASH_LENGTH = 12;

    /**
     * Stores the strips as the user's current character.
     *
     * @param  int  $userId  the owner
     * @param  array<string, array{image: GdImage, frames: int, rate: int}>  $strips  strips keyed by animation
     * @return array{hash: string, animations: array<string, array{frames: int, rate: int}>}
     */
    public function put(int $userId, array $strips): array
    {
        $encoded = array_map(fn (array $strip): string => $this->encode($strip['image']), $strips);
        $hash = substr(sha1(implode('', $encoded)), 0, self::HASH_LENGTH);
        $disk = Storage::disk('public');

        foreach ($encoded as $animation => $png) {
            $disk->put(self::ROOT."/{$userId}/{$hash}/{$animation}.png", $png);
        }

        foreach ($disk->directories(self::ROOT."/{$userId}") as $directory) {
            if (basename($directory) !== $hash) {
                $disk->deleteDirectory($directory);
            }
        }

        return [
            'hash' => $hash,
            'animations' => array_map(fn (array $strip): array => ['frames' => $strip['frames'], 'rate' => $strip['rate']], $strips),
        ];
    }

    /**
     * Deletes everything stored for the user.
     *
     * @param  int  $userId  the owner
     * @return void
     */
    public function forget(int $userId): void
    {
        Storage::disk('public')->deleteDirectory(self::ROOT."/{$userId}");
    }

    /**
     * Encodes a strip as PNG bytes.
     *
     * @param  GdImage  $image  the strip
     * @return string PNG file bytes
     */
    private function encode(GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
