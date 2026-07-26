<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClaimProvisionedGrantsRequest;
use App\Http\Requests\ConfirmProvisionedSetupRequest;
use App\Services\AccountProvisioningService;
use App\Services\Provisioning\DeviceClaimResolver;
use Illuminate\Http\JsonResponse;

/**
 * Hands a user's provisioned grants (held encrypted in the cache) to the
 * slayer-cli client. Guarded by the hook.token middleware; each grant is
 * served once — {@see AccountProvisioningService::claim()} consumes it.
 */
final class ProvisionedAccountController extends Controller
{
    /**
     * @param  AccountProvisioningService  $provisioning  supplies + consumes the user's claimable grants
     * @param  DeviceClaimResolver  $resolver  maps a claim fingerprint to a device
     */
    public function __construct(
        private readonly AccountProvisioningService $provisioning,
        private readonly DeviceClaimResolver $resolver,
    ) {}

    /**
     * Return the authenticated user's claimable grants for the calling
     * device, their verified memberships, and the org accounts this device
     * should remove.
     *
     * @param  ClaimProvisionedGrantsRequest  $request  carries the hook-authenticated user and optional device fingerprint
     * @return JsonResponse {accounts, memberships, remove}
     */
    public function index(ClaimProvisionedGrantsRequest $request): JsonResponse
    {
        $user = $request->user('hook');
        $fingerprint = $request->validated('device_id');

        $accounts = $this->provisioning->claim($user, $fingerprint);
        $device = $this->resolver->resolve($user, $fingerprint);
        $memberships = $this->provisioning->memberships($user);
        $remove = $this->provisioning->removable($user, $device);

        return response()->json([
            'accounts' => $accounts,
            'memberships' => $memberships,
            'remove' => $remove,
        ]);
    }

    /**
     * Confirm the CLI's reconcile. Accepts `{set_up:[{org_uuid}], removed:[{org_uuid}], device_id?}`;
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
        $device = $this->resolver->resolve($user, $request->validated('device_id'));

        $result = $this->provisioning->confirmSetup($user, $setUp, $removed, $device);

        return response()->json($result);
    }
}
