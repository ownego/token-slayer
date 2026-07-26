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
     * Confirm the CLI's reconcile. Accepts `{set_up:[{org_uuid}], removed:[{org_uuid}]}`;
     * also accepts the legacy `{accounts:[{org_uuid}]}` as `set_up` (old clients).
     *
     * @param  Request  $request  carries the hook-authenticated user and the body
     * @return JsonResponse {confirmed, deprovisioned}
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'set_up' => ['required_without_all:accounts,removed', 'array'],
            'set_up.*.org_uuid' => ['required_with:set_up', 'uuid'],
            'removed' => ['required_without_all:accounts,set_up', 'array'],
            'removed.*.org_uuid' => ['required_with:removed', 'uuid'],
            'accounts' => ['required_without_all:set_up,removed', 'array'],
            'accounts.*.org_uuid' => ['required_with:accounts', 'uuid'],
        ]);

        $setUp = array_column($validated['set_up'] ?? $validated['accounts'] ?? [], 'org_uuid');
        $removed = array_column($validated['removed'] ?? [], 'org_uuid');

        return response()->json(
            $this->provisioning->confirmSetup($request->user('hook'), $setUp, $removed),
        );
    }
}
