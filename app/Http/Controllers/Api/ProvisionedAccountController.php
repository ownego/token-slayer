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
     * Return the authenticated user's claimable grants, verified memberships,
     * and the org accounts to remove. Consumes the claimable grants.
     *
     * @param  Request  $request  carries the hook-authenticated user
     * @return JsonResponse {accounts, memberships, remove}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user('hook');

        return response()->json([
            'accounts' => $this->provisioning->claim($user),
            'memberships' => $this->provisioning->memberships($user),
            'remove' => $this->provisioning->removable($user),
        ]);
    }

    /**
     * Confirm the org accounts the CLI actually finished setting up during
     * `token-slayer setup`, promoting each to a tracked membership.
     *
     * @param  Request  $request  carries the hook-authenticated user and the
     *                            `{accounts: [{org_uuid}]}` confirmation body
     * @return JsonResponse the confirmed count ({confirmed: <int>})
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'accounts' => ['required', 'array'],
            'accounts.*.org_uuid' => ['required', 'uuid'],
        ]);

        $orgUuids = array_column($validated['accounts'], 'org_uuid');

        return response()->json([
            'confirmed' => $this->provisioning->confirmSetup($request->user('hook'), $orgUuids),
        ]);
    }
}
