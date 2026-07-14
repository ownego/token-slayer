<?php

namespace App\Http\Controllers\Api;

use App\Events\BossKilled;
use App\Events\BossSpawned;
use App\Events\FighterCharging;
use App\Events\FighterIdled;
use App\Events\FighterJoined;
use App\Events\HitDealt;
use App\Http\Controllers\Controller;
use App\Models\Boss;
use App\Models\Event;
use App\Services\AccountResolver;
use App\Services\DamageService;
use App\Services\FighterChargingCache;
use App\Services\TranscriptReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(
        private DamageService $damage,
        private TranscriptReader $transcripts,
        private FighterChargingCache $chargingCache,
        private AccountResolver $accounts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user('hook');
        $payload = $request->all();

        $accountEmail = $this->trimmedStringOrNull($payload['account_email'] ?? null);
        $accountSource = $this->trimmedStringOrNull($payload['account_source'] ?? null);
        $accountOrgId = $this->trimmedStringOrNull($payload['account_org_id'] ?? null);
        $accountId = $this->accounts->resolve($accountOrgId, $accountEmail);
        $clientVersion = $this->trimmedStringOrNull($payload['client_version'] ?? null);
        $customActivity = $this->trimmedStringOrNull($payload['custom_activity'] ?? null);

        $hookName = $payload['hook_event_name'] ?? 'unknown';
        $eventType = $this->normalizeEventType($hookName);
        $provider = $request->query('provider', 'claude-code');
        $tokens = $this->resolveStopTokens($eventType, $payload);

        $user->forceFill([
            'last_event_at' => now(),
            'client_version' => $clientVersion ?? $user->client_version,
        ])->save();

        if ($eventType === 'user-prompt-submit' || $eventType === 'pre-invocation') {
            $activity = $this->truncateActivity($customActivity ?? 'thinking…');
            $this->chargingCache->put($user->id, $activity);
            $this->dispatchSafely(new FighterCharging($user, $activity, $this->aliveBoss()));
        }

        if ($eventType === 'pre-tool-use') {
            $activity = $this->truncateActivity($customActivity ?? $this->summarizeToolUse($payload));
            $this->chargingCache->put($user->id, $activity);
            $this->dispatchSafely(new FighterCharging($user, $activity, $this->aliveBoss()));
        }

        if ($eventType === 'session-start') {
            $this->dispatchSafely(new FighterJoined($user, $this->aliveBoss()));
        }

        if ($eventType === 'stop') {
            // Trackers that only emit Stop events (claude.ai, cowork) carry no
            // pre-action signal, so surface a persistent source label as their
            // charging activity instead of clearing the bubble outright.
            $activityLabel = $this->providerActivityLabel($provider);

            if ($tokens > 0) {
                $boss = $this->aliveBoss();

                Event::create([
                    'user_id' => $user->id,
                    'boss_id' => $boss?->id,
                    'provider' => $provider,
                    'tokens' => $tokens,
                    'session_id' => $payload['session_id'] ?? null,
                    'account_id' => $accountId,
                    'account_email' => $accountEmail,
                    'account_source' => $accountSource,
                    'account_org_id' => $accountOrgId,
                ]);

                $result = $this->damage->apply($user, $tokens);

                foreach ($result->killedBosses as $killed) {
                    $this->dispatchSafely(new BossKilled($killed, $user));
                }

                if (! empty($result->killedBosses)) {
                    $this->dispatchSafely(new BossSpawned($result->boss));
                }

                $this->dispatchSafely(new HitDealt($user, $tokens, $result->boss));

                if ($activityLabel !== null) {
                    // Dispatched after HitDealt: the client clears the charge
                    // when the attack lands, so re-set the bubble afterwards.
                    $this->chargingCache->put($user->id, $activityLabel);
                    $this->dispatchSafely(new FighterCharging($user, $activityLabel, $result->boss));
                } else {
                    $this->chargingCache->forget($user->id);
                }
            } else {
                // Nothing to damage with — still clear the charging visual
                // so the fighter doesn't stay stuck mid-charge.
                $this->chargingCache->forget($user->id);
                $this->dispatchSafely(new FighterIdled($user));
            }
        }

        return response()->json(['ok' => true], 201);
    }

    private function aliveBoss(): ?Boss
    {
        return Boss::where('status', 'alive')->orderByDesc('number')->first();
    }

    /**
     * Persistent charging-bubble label for trackers that only emit Stop events
     * and therefore have no per-action activity to show. Returns null for
     * providers (e.g. claude-code) whose charging state is managed elsewhere.
     */
    private function providerActivityLabel(string $provider): ?string
    {
        return match ($provider) {
            'claude-ai' => 'claude.ai',
            'cowork' => 'cowork',
            default => null,
        };
    }

    private function normalizeEventType(string $hookName): string
    {
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $hookName));
    }

    /**
     * Build a privacy-safe "what is the agent doing" label from a PreToolUse
     * payload. Only the tool name is ever surfaced by default — no command
     * text, file paths, search patterns, or URLs, since the charging bubble
     * is visible to everyone watching the battlefield. A client-supplied
     * `custom_activity` payload field always takes priority over this and
     * is applied by the caller before this method is reached.
     *
     * @param  array<string, mixed>  $payload
     */
    private function summarizeToolUse(array $payload): string
    {
        $tool = (string) ($payload['tool_name'] ?? 'tool');

        if (str_starts_with($tool, 'mcp__')) {
            $segments = explode('__', $tool, 3);

            if (($segments[1] ?? '') !== '') {
                return $this->truncateActivity('MCP: '.$segments[1]);
            }
        }

        $label = match ($tool) {
            'Bash', 'BashOutput', 'KillShell', 'run_command' => 'Bash',
            'Read', 'read_file', 'view_file' => 'Read file',
            'Edit', 'Write', 'MultiEdit', 'NotebookEdit', 'write_file', 'write_to_file', 'replace_file_content', 'multi_replace_file_content' => 'Edit file',
            'Grep', 'grep_search', 'Glob' => 'Search',
            'WebFetch', 'WebSearch' => 'Web',
            'Task' => 'Agent',
            'TodoWrite' => 'TodoWrite',
            default => $tool,
        };

        return $this->truncateActivity($label);
    }

    /**
     * Truncate a charging-bubble activity label to a display-friendly
     * length, appending an ellipsis when truncation occurs. Applied to both
     * default tool-name labels and client-supplied `custom_activity` values.
     *
     * @param  string  $activity
     * @return string
     */
    private function truncateActivity(string $activity): string
    {
        return mb_strlen($activity) > 40 ? mb_substr($activity, 0, 39).'…' : $activity;
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

        $path = $payload['transcript_path'] ?? $payload['transcriptPath'] ?? null;
        if (! is_string($path)) {
            return 0;
        }

        // The transcript file is sometimes still being flushed at the
        // instant the Stop hook fires, so the latest assistant entry
        // hasn't landed yet. Retry briefly to ride out the race.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $tokens = $this->transcripts->latestTurnOutputTokens($path);
            if ($tokens > 0) {
                return $tokens;
            }
            if ($attempt < 2) {
                usleep(100_000);
            }
        }

        return 0;
    }

    /**
     * Broadcasts are best-effort: the damage transaction has already committed,
     * so a downed websocket or misconfigured driver must not 500 the hook.
     */
    private function dispatchSafely(object $event): void
    {
        rescue(fn () => event($event));
    }

    /**
     * Normalize an incoming payload string: trimmed non-empty string or null.
     *
     * @param  mixed  $value
     * @return ?string
     */
    private function trimmedStringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
