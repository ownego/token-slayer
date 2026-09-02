<?php

use App\Services\CodexOAuthClient;
use Illuminate\Support\Facades\Http;

it('posts the refresh token to the OpenAI revoke endpoint', function (): void {
    Http::fake(['auth.openai.com/oauth/revoke' => Http::response([], 200)]);

    (new CodexOAuthClient)->revoke('a-refresh-token');

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://auth.openai.com/oauth/revoke'
            && $request['token'] === 'a-refresh-token'
            && $request['token_type_hint'] === 'refresh_token'
            && $request['client_id'] === 'app_EMoamEEZ73f0CkXaXp7hrann';
    });
});

it('does not throw when the revoke call fails', function (): void {
    Http::fake(['auth.openai.com/oauth/revoke' => Http::response([], 500)]);

    (new CodexOAuthClient)->revoke('a-refresh-token');

    expect(true)->toBeTrue();
});
