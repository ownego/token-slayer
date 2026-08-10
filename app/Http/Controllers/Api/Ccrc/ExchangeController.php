<?php

namespace App\Http\Controllers\Api\Ccrc;

use App\Http\Controllers\Controller;
use App\Models\IdeAccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeController extends Controller
{
    /**
     * Exchange a CCRC-flow one-time token for an IDENTITY — and nothing else.
     *
     * Deliberately does NOT issue a bearer like the IDE flow does. This
     * matters because a bearer, once issued, lives forever and — combined
     * with `/api/ide/auth/session-url` and `EstablishIdeSession` (a global
     * web middleware) — logs straight into the user's account.
     *
     * The token consumed here is `IdeAccessToken::issueOneTimeCcrc()`'s own
     * `kind`, distinct from the IDE flow's `one_time`: `consumeOneTimeCcrc()`
     * only ever matches that kind, so a token minted for this endpoint is
     * never redeemable on `/api/ide/auth/exchange` and can never become a
     * bearer that way. The CCRC hub only ever needs a name, so it must never
     * be handed anything more powerful than that.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        $user = IdeAccessToken::consumeOneTimeCcrc($data['token'], $data['state']);

        if ($user === null || blank($user->slack_user_id)) {
            return response()->json(['error' => 'token_invalid_or_expired'], 410);
        }

        return response()->json([
            'slackUserId' => $user->slack_user_id,
            'handle' => $user->displayHandle(),
        ]);
    }
}
