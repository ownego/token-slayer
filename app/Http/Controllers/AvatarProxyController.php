<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AvatarProxyController extends Controller
{
    public function __invoke(User $user): Response
    {
        if (! $user->avatar_url) {
            abort(404);
        }

        $payload = Cache::remember(
            "avatar:{$user->id}:".sha1($user->avatar_url),
            now()->addDay(),
            function () use ($user): ?array {
                // A DNS failure/timeout throws ConnectionException rather
                // than returning a response ->successful() can check —
                // caught live on staging via a seeded avatar_url pointing
                // at a non-resolvable host.
                try {
                    $response = Http::timeout(5)->get($user->avatar_url);
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
            },
        );

        if (! $payload) {
            abort(404);
        }

        return response($payload['body'], 200, [
            'Content-Type' => $payload['contentType'],
            'Cache-Control' => 'public, max-age=86400',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}
