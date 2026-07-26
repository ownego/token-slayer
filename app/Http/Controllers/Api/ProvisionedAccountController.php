<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConfirmProvisionedSetupRequest;
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
        $accounts = $this->provisioning->claim($user);
        $memberships = $this->provisioning->memberships($user);
        $remove = $this->provisioning->removable($user);

        return response()->json([
            'accounts' => $accounts,
            'memberships' => $memberships,
            'remove' => $remove,
        ]);
    }

    /**
     * Confirm the CLI's reconcile. Accepts `{set_up:[{org_uuid}], removed:[{org_uuid}]}`;
     * also accepts the legacy `{accounts:[{org_uuid}]}` as `set_up` (old clients).
     *
     * @param  ConfirmProvisionedSetupRequest  $request  carries the hook-authenticated user and the validated body
     * @return JsonResponse {confirmed, deprovisioned}
     */
    public function confirm(ConfirmProvisionedSetupRequest $request): JsonResponse
    {
        $user = $request->user('hook');
        // `set_up` falls back to the legacy `accounts` key for old clients.
        $setUp = array_column($request->validated('set_up') ?? $request->validated('accounts') ?? [], 'org_uuid');
        $removed = array_column($request->validated('removed') ?? [], 'org_uuid');

        $result = $this->provisioning->confirmSetup($user, $setUp, $removed);

        return response()->json($result);
    }
}
