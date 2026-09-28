<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Serves a player's Slack avatar from our own origin, so canvases can read it
 * (CORS) and Slack is hit about once a week per player, not once per viewer.
 */
class AvatarProxyController extends Controller
{
    /**
     * How long the image stays cached, in seconds, on the server and in the
     * browser. Safe for a week because every page links the image through
     * User::avatarProxyUrl(), whose `?v=` changes with the Slack URL.
     *
     * @var int
     */
    public const int CACHE_SECONDS = 604800;

    /**
     * The Slack image size fetched: the avatar shows at 26-40px, and the
     * stored 512px originals measured up to ~600 KB each.
     *
     * @var int
     */
    private const int FETCH_SIZE = 192;

    /**
     * Returns the player's avatar image, or 404 when they have none or Slack
     * can't be reached.
     *
     * @param  User  $user
     * @return Response
     */
    public function __invoke(User $user): Response
    {
        if (! $user->avatar_url) {
            abort(404);
        }

        // Keyed by the Slack URL (a new avatar is fetched at once) and the
        // size, so the 512px bodies cached before this change are not reused.
        $payload = Cache::remember(
            "avatar:{$user->id}:".self::FETCH_SIZE.":".sha1($user->avatar_url),
            now()->addSeconds(self::CACHE_SECONDS),
            fn (): ?array => $this->fetch($this->sized($user->avatar_url)) ?? $this->fetch($user->avatar_url),
        );

        if (! $payload) {
            abort(404);
        }

        return response($payload['body'], 200, [
            'Content-Type' => $payload['contentType'],
            'Cache-Control' => 'public, max-age='.self::CACHE_SECONDS,
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * The same Slack avatar at FETCH_SIZE: Slack serves each size at the same
     * path with a `_<size>` suffix. A URL without that suffix is returned as-is.
     *
     * @param  string  $url
     * @return string
     */
    private function sized(string $url): string
    {
        return preg_replace('/_(?:\d+|original)(\.\w+)$/', '_'.self::FETCH_SIZE.'$1', $url) ?? $url;
    }

    /**
     * Downloads one image, or null on any failure (a DNS failure or timeout
     * throws ConnectionException rather than returning a response).
     *
     * @param  string  $url
     * @return array{body: string, contentType: string}|null
     */
    private function fetch(string $url): ?array
    {
        try {
            $response = Http::timeout(5)->get($url);
        } catch (ConnectionException) {
            return null;
        }
        if (! $response->successful()) {
            return null;
        }

        return [
            'body' => $response->body(),
            'contentType' => $response->header('Content-Type') ?: 'image/jpeg',
        ];
    }
}
