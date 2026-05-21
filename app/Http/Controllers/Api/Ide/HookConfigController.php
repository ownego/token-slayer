<?php

namespace App\Http\Controllers\Api\Ide;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HookConfigController extends Controller
{
    /**
     * The Claude Code hook events aiorg subscribes to.
     *
     * @var list<string>
     */
    private const EVENT_NAMES = ['SessionStart', 'UserPromptSubmit', 'PreToolUse', 'PostToolUse', 'Stop'];

    /**
     * Single source of truth for which Claude Code hook events aiorg cares
     * about and what command to wire them to. Consumed by the IDE extension's
     * HookManager. The /install shell script can adopt this later (out of
     * scope for v1).
     */
    public function __invoke(): JsonResponse
    {
        $namespace = config('app.hook_namespace');
        $command = sprintf('bash "$HOME/.config/%s/send-hook.sh"', $namespace);

        return response()->json([
            'namespace' => $namespace,
            'eventsUrl' => url('/api/events'),
            'events' => array_map(
                fn (string $name): array => ['name' => $name, 'command' => $command],
                self::EVENT_NAMES,
            ),
        ]);
    }
}
