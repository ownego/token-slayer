<?php

namespace App\Http\Controllers\Api\Ide;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'handle' => $user->displayHandle(),
                'avatarUrl' => route('avatar', $user),
            ],
        ]);
    }
}
