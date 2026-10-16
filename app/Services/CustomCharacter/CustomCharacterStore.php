<?php

namespace App\Services\CustomCharacter;

use App\Exceptions\CustomCharacterStorageException;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Owns where a player's fighter strips live and every write to them. Strips are
 * kept under `custom-characters/{user}/{hash}/` once confirmed and under
 * `custom-characters/{user}/staged/{hash}/` while only previewed, so a draft can
 * never be mistaken for the fighter in use. The folder name carries a hash of
 * the strips' content: a changed fighter gets a new URL and a cached copy of the
 * old one cannot be served for it.
 *
 * Every write is checked (a full or unwritable disk is an error, not silence),
 * and nothing is deleted here until the caller says a database change is safe.
 */
class CustomCharacterStore
{
    /**
     * Root folder, on the disk, that holds every user's character.
     *
     * @var string
     */
    public const string ROOT = 'custom-characters';

    /**
     * The folder, under a user's, that holds strips not yet confirmed.
     *
     * @var string
     */
    public const string STAGED = 'staged';

    /**
     * The animations a fighter ships, one strip (png) each.
     *
     * @var list<string>
     */
    public const array ANIMATIONS = ['idle', 'walk', 'attack', 'death'];

    /**
     * Length of the version hash used in the folder name.
     *
     * @var int
     */
    private const int HASH_LENGTH = 12;

    /**
     * Folder, on the disk, that holds one confirmed version of a user's strips.
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
     * Folder, on the disk, that holds one staged (previewed, not confirmed) version.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash the strips were stored under
     * @return string
     */
    public static function stagedFolder(int $userId, string $hash): string
    {
        return self::ROOT."/{$userId}/".self::STAGED."/{$hash}";
    }

    /**
     * The disk the strips live on: the one named in config, `public` by default.
     *
     * @return Filesystem
     */
    public function disk(): Filesystem
    {
        return Storage::disk((string) config('token_slayer.custom_character.disk'));
    }

    /**
     * Where a version's strips are served from. A path when the disk is served
     * from the app's own origin (the battlefield reads the strips' pixels, which
     * a cross-origin image forbids), the full URL when it is another host (a
     * bucket or CDN, which then has to send CORS headers).
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @param  bool  $staged  whether the version is a staged draft
     * @return string
     */
    public function baseUrl(int $userId, string $hash, bool $staged = false): string
    {
        $folder = $staged ? self::stagedFolder($userId, $hash) : self::folder($userId, $hash);
        $url = $this->disk()->url($folder);
        $host = parse_url($url, PHP_URL_HOST);

        if ($host === null || $host === false || $host === parse_url((string) config('app.url'), PHP_URL_HOST)) {
            return (string) parse_url($url, PHP_URL_PATH);
        }

        return $url;
    }

    /**
     * The URL of each of a version's strips.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @param  bool  $staged  whether the version is a staged draft
     * @return array<string, string> strip URL keyed by animation
     */
    public function stripUrls(int $userId, string $hash, bool $staged = false): array
    {
        $base = $this->baseUrl($userId, $hash, $staged);

        return array_combine(self::ANIMATIONS, array_map(fn (string $animation): string => "{$base}/{$animation}.png", self::ANIMATIONS));
    }

    /**
     * Writes the strips to the staged area, checking every file.
     *
     * @param  int  $userId  the owner
     * @param  array<string, array{image: GdImage, frames: int, rate: int}>  $strips  strips keyed by animation
     * @return array{hash: string, animations: array<string, array{frames: int, rate: int}>}
     *
     * @throws CustomCharacterStorageException when a file cannot be written (what was written is removed)
     */
    public function stage(int $userId, array $strips): array
    {
        $startedAt = microtime(true);
        $encoded = array_map(fn (array $strip): string => $this->encode($strip['image']), $strips);
        $hash = substr(sha1(implode('', $encoded)), 0, self::HASH_LENGTH);
        $folder = self::stagedFolder($userId, $hash);
        $disk = $this->usableDisk();
        $existed = $this->safely(fn (): bool => $disk->exists("{$folder}/idle.png"));

        try {
            foreach ($encoded as $animation => $png) {
                $this->guardDeadline($startedAt);
                $this->write($disk, "{$folder}/{$animation}.png", $png);
            }
            $this->guardDeadline($startedAt);
        } catch (CustomCharacterStorageException $exception) {
            if (! $existed) {
                $this->safely(fn () => $disk->deleteDirectory($folder));
            }

            throw $exception;
        }

        return [
            'hash' => $hash,
            'animations' => array_map(fn (array $strip): array => ['frames' => $strip['frames'], 'rate' => $strip['rate']], $strips),
        ];
    }

    /**
     * Copies a staged version into the confirmed folder, then drops the staged
     * copy. The staged files stay whole until all four strips are in place, so a
     * failure half way leaves the draft usable and no half-confirmed folder. A
     * version that is already confirmed (the same strips confirmed before) just
     * loses its staged copy; a confirmed folder missing some strips is completed.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @return void
     *
     * @throws CustomCharacterStorageException when the version was never staged, or a file cannot be copied
     */
    public function promote(int $userId, string $hash): void
    {
        $startedAt = microtime(true);
        $disk = $this->usableDisk();
        $from = self::stagedFolder($userId, $hash);
        $to = self::folder($userId, $hash);

        if ($this->isComplete($disk, $to)) {
            $this->guardDeadline($startedAt);
            $this->safely(fn () => $disk->deleteDirectory($from));

            return;
        }

        if (! $this->isComplete($disk, $from)) {
            throw new CustomCharacterStorageException('The built fighter\'s files are gone. Please build it again.');
        }

        try {
            foreach (self::ANIMATIONS as $animation) {
                $this->guardDeadline($startedAt);
                if (! $disk->copy("{$from}/{$animation}.png", "{$to}/{$animation}.png")) {
                    throw new CustomCharacterStorageException;
                }
            }

            if (! $this->isComplete($disk, $to)) {
                throw new CustomCharacterStorageException;
            }
            $this->guardDeadline($startedAt);
        } catch (Throwable $exception) {
            $this->safely(fn () => $disk->deleteDirectory($to));

            throw $exception instanceof CustomCharacterStorageException ? $exception : new CustomCharacterStorageException;
        }

        $this->guardDeadline($startedAt);
        $this->safely(fn () => $disk->deleteDirectory($from));
    }

    /**
     * Deletes every staged version of the user except the one named.
     *
     * @param  int  $userId  the owner
     * @param  string  $keep  the staged version hash to keep
     * @return void
     */
    public function pruneStaged(int $userId, string $keep): void
    {
        $this->safely(function () use ($userId, $keep): void {
            $disk = $this->disk();

            foreach ($disk->directories(self::ROOT."/{$userId}/".self::STAGED) as $directory) {
                if (basename($directory) !== $keep) {
                    $this->safely(fn () => $disk->deleteDirectory($directory));
                }
            }
        });
    }

    /**
     * Deletes the user's whole staged area (their draft's files).
     *
     * @param  int  $userId  the owner
     * @return void
     */
    public function discardStaged(int $userId): void
    {
        $this->safely(fn () => $this->disk()->deleteDirectory(self::ROOT."/{$userId}/".self::STAGED));
    }

    /**
     * Deletes every confirmed version of the user except the ones listed. The
     * staged area is never touched.
     *
     * @param  int  $userId  the owner
     * @param  list<string>  $keep  the version hashes to keep
     * @return void
     */
    public function prune(int $userId, array $keep): void
    {
        $this->safely(function () use ($userId, $keep): void {
            $disk = $this->disk();

            foreach ($disk->directories(self::ROOT."/{$userId}") as $directory) {
                if (basename($directory) !== self::STAGED && ! in_array(basename($directory), $keep, true)) {
                    $this->safely(fn () => $disk->deleteDirectory($directory));
                }
            }
        });
    }

    /**
     * Deletes one confirmed version's files.
     *
     * @param  int  $userId  the owner
     * @param  string  $hash  the version hash
     * @return void
     */
    public function forgetVersion(int $userId, string $hash): void
    {
        $this->safely(fn () => $this->disk()->deleteDirectory(self::folder($userId, $hash)));
    }

    /**
     * Deletes everything stored for the user.
     *
     * @param  int  $userId  the owner
     * @return void
     */
    public function forget(int $userId): void
    {
        $this->safely(fn () => $this->disk()->deleteDirectory(self::ROOT."/{$userId}"));
    }

    /**
     * The disk, or a storage failure when it cannot even be opened (an unwritable
     * root fails when the disk is built, before any write is tried).
     *
     * @return Filesystem
     *
     * @throws CustomCharacterStorageException when the disk cannot be opened
     */
    private function usableDisk(): Filesystem
    {
        try {
            return $this->disk();
        } catch (Throwable) {
            throw new CustomCharacterStorageException;
        }
    }

    /**
     * Whether a folder holds all of a version's strips.
     *
     * @param  Filesystem  $disk  the disk
     * @param  string  $folder  the version's folder
     * @return bool
     */
    private function isComplete(Filesystem $disk, string $folder): bool
    {
        foreach (self::ANIMATIONS as $animation) {
            if (! $this->safely(fn (): bool => $disk->exists("{$folder}/{$animation}.png"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Writes one file and checks it was written.
     *
     * @param  Filesystem  $disk  the disk
     * @param  string  $path  where to write
     * @param  string  $contents  the bytes
     * @return void
     *
     * @throws CustomCharacterStorageException when the disk refuses or fails the write
     */
    private function write(Filesystem $disk, string $path, string $contents): void
    {
        try {
            $written = $disk->put($path, $contents);
        } catch (Throwable) {
            $written = false;
        }

        if ($written === false) {
            throw new CustomCharacterStorageException;
        }
    }

    /**
     * Runs a disk call whose failure must not stop the caller (a cleanup after the
     * database already changed): a failure gives the fallback instead of throwing.
     *
     * @param  callable  $call  the disk call
     * @param  mixed  $fallback  what to give if it throws
     * @return mixed
     */
    private function safely(callable $call, mixed $fallback = false): mixed
    {
        return rescue($call, $fallback, report: true);
    }

    /**
     * Aborts a slow storage operation before another request can acquire the player's lock.
     *
     * @param  float  $startedAt  when this operation began
     * @return void
     *
     * @throws CustomCharacterStorageException when too little lock lifetime remains
     */
    private function guardDeadline(float $startedAt): void
    {
        if (microtime(true) - $startedAt >= (int) config('token_slayer.custom_character.lock_hold_seconds') - 5) {
            throw new CustomCharacterStorageException('The storage is too slow right now. Try again in a moment.');
        }
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
