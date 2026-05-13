<?php

namespace App\Http\Controllers\Api;

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterCharging;
use App\Events\FighterJoined;
use App\Events\HitDealt;
use App\Http\Controllers\Controller;
use App\Models\Boss;
use App\Models\Event;
use App\Services\DamageService;
use App\Services\TranscriptReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(
        private DamageService $damage,
        private TranscriptReader $transcripts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user('hook');
        $payload = $request->all();

        $hookName = $payload['hook_event_name'] ?? 'unknown';
        $eventType = $this->normalizeEventType($hookName);
        $provider = $request->query('provider', 'claude-code');
        $tokens = $this->resolveStopTokens($eventType, $payload);

        $boss = Boss::where('status', 'alive')->orderByDesc('number')->first();

        Event::create([
            'user_id' => $user->id,
            'boss_id' => $boss?->id,
            'provider' => $provider,
            'event_type' => $eventType,
            'tokens' => $tokens,
            'session_id' => $payload['session_id'] ?? null,
            'raw_payload' => $payload,
        ]);

        $user->forceFill(['last_event_at' => now()])->save();

        if ($eventType === 'user-prompt-submit') {
            $this->dispatchSafely(new FighterCharging($user, 'thinking…'));
        }

        if ($eventType === 'pre-tool-use') {
            $this->dispatchSafely(new FighterCharging($user, $this->summarizeToolUse($payload)));
        }

        if ($eventType === 'session-start') {
            $this->dispatchSafely(new FighterJoined($user));
        }

        if ($eventType === 'stop' && $tokens > 0) {
            $result = $this->damage->apply($user, $tokens);

            foreach ($result->killedBosses as $killed) {
                $this->dispatchSafely(new BossKilled($killed, $user));
            }

            if (! empty($result->killedBosses)) {
                $this->dispatchSafely(new BossSpawned($result->boss));
            }

            $this->dispatchSafely(new HitDealt($user, $tokens, $result->boss));
        }

        return response()->json(['ok' => true], 201);
    }

    private function normalizeEventType(string $hookName): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $hookName));
    }

    /**
     * Build a short "what is the agent doing" string from a PreToolUse payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function summarizeToolUse(array $payload): string
    {
        $tool = (string) ($payload['tool_name'] ?? 'tool');
        $input = (array) ($payload['tool_input'] ?? []);

        $detail = match ($tool) {
            'Bash' => '$ '.(string) ($input['command'] ?? ''),
            'Read', 'Edit', 'Write', 'NotebookEdit' => $tool.': '.basename((string) ($input['file_path'] ?? '')),
            'Grep' => 'Grep: '.(string) ($input['pattern'] ?? ''),
            'Glob' => 'Glob: '.(string) ($input['pattern'] ?? ''),
            'WebFetch' => 'WebFetch: '.(string) ($input['url'] ?? ''),
            'TodoWrite' => 'TodoWrite',
            'Task' => 'Agent: '.(string) ($input['description'] ?? ''),
            default => $tool,
        };

        return mb_strlen($detail) > 40 ? mb_substr($detail, 0, 39).'…' : $detail;
    }

    /**
     * Resolve the damage tokens for a Stop event. Inline payload wins so
     * cross-machine deployments can extract tokens client-side; otherwise
     * fall back to reading the transcript when the hook host is the same
     * machine as the server. Non-Stop events return null.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveStopTokens(string $eventType, array $payload): ?int
    {
        if ($eventType !== 'stop') {
            return null;
        }

        $inline = (int) ($payload['tokens'] ?? 0);
        if ($inline > 0) {
            return $inline;
        }

        $path = $payload['transcript_path'] ?? null;
        if (! is_string($path)) {
            return 0;
        }

        return $this->transcripts->latestTurnOutputTokens($path);
    }

    /**
     * Broadcasts are best-effort: the damage transaction has already committed,
     * so a downed websocket or misconfigured driver must not 500 the hook.
     */
    private function dispatchSafely(object $event): void
    {
        rescue(fn () => event($event));
    }
}
