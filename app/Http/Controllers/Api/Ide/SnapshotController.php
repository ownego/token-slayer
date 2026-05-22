<?php

namespace App\Http\Controllers\Api\Ide;

use App\Http\Controllers\Controller;
use App\Models\Boss;
use App\Models\Event;
use App\Services\FighterChargingCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SnapshotController extends Controller
{
    /**
     * Returns the current battlefield snapshot for the authenticated IDE user.
     *
     * The extension polls this so the status bar can show boss HP / your damage
     * without the user having to open the webview sidebar first (which is
     * what activates the live Echo bridge).
     */
    public function __invoke(Request $request, FighterChargingCache $chargingCache): JsonResponse
    {
        $user = $request->user();

        $boss = Boss::where('status', 'alive')
            ->orderByDesc('number')
            ->first(['id', 'number', 'name', 'max_hp', 'current_hp']);

        $yourDamage = 0;
        if ($boss !== null) {
            $yourDamage = (int) Event::query()
                ->where('boss_id', $boss->id)
                ->where('user_id', $user->id)
                ->sum('tokens');
        }

        $charging = $chargingCache->many([$user->id])[$user->id] ?? null;

        return response()->json([
            'boss' => $boss === null ? null : [
                'id' => $boss->number,
                'name' => $boss->name,
                'maxHp' => $boss->max_hp,
                'currentHp' => $boss->current_hp,
            ],
            'yourDamage' => $yourDamage,
            'charging' => $charging['activity'] ?? null,
        ]);
    }
}
