<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\CodexConnectException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use App\Services\CodexProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-authenticated endpoints for `token-slayer admin codex-connect`/
 * `codex-provision` — see {@see CodexProvisioningService} for the actual
 * connect/provision logic; this controller only validates the request
 * shape and maps outcomes to HTTP responses.
 */
class CodexAdminController extends Controller
{
    /**
     * @param  CodexProvisioningService  $codex
     * @return void
     */
    public function __construct(private readonly CodexProvisioningService $codex) {}

    /**
     * Step A: connect a shared Codex account.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function connect(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'auth_json' => ['required', 'array'],
        ]);

        try {
            $account = $this->codex->connectAccount($data['auth_json'], $data['name']);
        } catch (CodexConnectException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => "Connected: {$account->name}"]);
    }

    /**
     * Step B: provision a device for an employee.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email'],
            'auth_json' => ['required', 'array'],
        ]);

        $account = Account::query()->where('provider', 'codex')->where('name', $data['account'])->first();
        if ($account === null) {
            return response()->json(['error' => "no Codex account named '{$data['account']}'"], 404);
        }

        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null) {
            return response()->json(['error' => "no user with email '{$data['email']}'"], 404);
        }

        try {
            $this->codex->provisionForDevice($account, $user, $data['auth_json']);
        } catch (CodexConnectException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => "Provisioned for {$data['email']}"]);
    }
}
