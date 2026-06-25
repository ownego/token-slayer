<?php

namespace App\Services\Slack;

use Illuminate\Support\Facades\Http;

class SlackProfileFetcher
{
    /**
     * Resolve a member's Slack "Display name" (e.g. "sonnh") via the Web API.
     *
     * Uses the workspace bot token (which carries the `users:read` scope) so any member's
     * profile can be read without their own login token. Falls back to the real name, then
     * null on any failure so callers can degrade gracefully.
     */
    public function displayNameFor(string $slackUserId): ?string
    {
        $token = config('services.slack.bot_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        $response = Http::withToken($token)
            ->get('https://slack.com/api/users.info', ['user' => $slackUserId]);

        if (! $response->successful() || $response->json('ok') !== true) {
            return null;
        }

        $displayName = $response->json('user.profile.display_name')
            ?: $response->json('user.profile.real_name');

        return filled($displayName) ? $displayName : null;
    }
}
