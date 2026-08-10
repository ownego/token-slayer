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
     * Deliberately does NOT issue a bearer like the IDE flow does. An IDE
     * bearer is longer-lived and reaches further than anything this flow
     * needs; the CCRC hub only ever needs a name, so it should never hold
     * more than that — not in memory, not in a log, not in a callback URL.
     *
     * The token consumed here carries `IdeAccessToken::issueOneTimeCcrc()`'s
     * own `kind`, distinct from the IDE flow's `one_time`, and
     * `consumeOneTimeCcrc()` only ever matches that kind. So a token minted
     * for this endpoint is not redeemable on `/api/ide/auth/exchange`, and an
     * IDE token is not redeemable here. Both directions have tests — without
     * them the two kinds would drift back into one on some later refactor,
     * and this endpoint's restraint would buy nothing.
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
