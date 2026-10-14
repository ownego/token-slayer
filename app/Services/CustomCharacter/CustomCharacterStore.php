<?php

namespace App\Services\CustomCharacter;

use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Writes a user's finished strips to the configured disk (`token_slayer.custom_character.disk`: `public` locally, `r2` on a host that serves them from a CDN). The folder name carries
 * a hash of the strips' content, so a changed character gets a new URL and
 * cached copies of the old one cannot be served for it; the previous version
 * is deleted once the new one is in place.
 */
class CustomCharacterStore
{
    /**
     * Root folder, on the configured disk, that holds every user's character.
     *
     * @var string
     */
    public const string ROOT = 'custom-characters';

    /**
     * Length of the version hash used in the folder name.
     *
     * @var int
     */
    private const int HASH_LENGTH = 12;

    /**
     * Folder, on the configured disk, that holds one version of a user's strips.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash the strips were stored under
     * @return string
     */
    public static function folder(int $userId, string $hash): string
    {
        return self::ROOT."/{$userId}/{$hash}";
    }

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
        $disk = $this->disk();

        foreach ($encoded as $animation => $png) {
            $disk->put(self::folder($userId, $hash)."/{$animation}.png", $png);
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
        $this->disk()->deleteDirectory(self::ROOT."/{$userId}");
    }

    /**
     * Where one version's strips are served from: a same-origin path on a local
     * disk, the full URL on any other driver (a CDN host is another origin, so
     * a client reading the strips' pixels needs CORS from it).
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash the strips were stored under
     * @return string
     */
    public function baseUrl(int $userId, string $hash): string
    {
        $url = $this->disk()->url(self::folder($userId, $hash));
        $driver = config('filesystems.disks.'.$this->diskName().'.driver');

        return $driver === 'local' ? (string) parse_url($url, PHP_URL_PATH) : $url;
    }

    /**
     * Name of the disk the strips live on.
     *
     * @return string
     */
    private function diskName(): string
    {
        return (string) config('token_slayer.custom_character.disk');
    }

    /**
     * The disk the strips live on.
     *
     * @return Filesystem
     */
    private function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
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
