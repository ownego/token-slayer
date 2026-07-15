<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hands a user's provisioned grants (held encrypted in the cache) to the
 * slayer-cli client. Guarded by the hook.token middleware; each grant is
 * served once — {@see AccountProvisioningService::claim()} consumes it.
 */
final class ProvisionedAccountController extends Controller
{
    /**
     * @param  AccountProvisioningService  $provisioning  supplies + consumes the user's claimable grants
     */
    public function __construct(private readonly AccountProvisioningService $provisioning) {}

    /**
     * Return the authenticated user's claimable grants and consume them.
     *
     * @param  Request  $request  carries the hook-authenticated user
     * @return JsonResponse the grants payload ({accounts: [...]})
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'accounts' => $this->provisioning->claim($request->user('hook')),
        ]);
    }
}
