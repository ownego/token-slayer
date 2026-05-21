<?php

namespace App\Http\Controllers\Api\Ide;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    /**
     * Paths the IDE webview is allowed to deep-link into via a signed URL.
     *
     * @var list<string>
     */
    private const ALLOWED_SESSION_PATHS = ['/battlefield', '/profile', '/history'];

    public function exchange(Request $request): JsonResponse|Response
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $user = IdeAccessToken::consumeOneTime($data['token'], $data['state']);

        if ($user === null) {
            return response()->json(['error' => 'token_invalid_or_expired'], 410);
        }

        [$bearer] = IdeAccessToken::issueBearer($user);

        return response()->json(['bearer' => $bearer]);
    }

    public function revoke(Request $request): Response
    {
        $plain = $request->bearerToken();

        if ($plain !== null) {
            IdeAccessToken::findActiveBearer($plain)?->revoke();
        }

        return response()->noContent();
    }

    public function sessionUrl(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => ['required', 'string'],
        ]);

        $pathOnly = parse_url($data['path'], PHP_URL_PATH) ?? '';

        if (! in_array($pathOnly, self::ALLOWED_SESSION_PATHS, true)) {
            return response()->json(['error' => 'path_not_allowed'], 422);
        }

        [$plain] = IdeAccessToken::issueSessionUrl($request->user(), $data['path'], 30);

        $separator = str_contains($data['path'], '?') ? '&' : '?';

        return response()->json([
            'url' => url($data['path']).$separator.'_t='.$plain,
        ]);
    }
}
