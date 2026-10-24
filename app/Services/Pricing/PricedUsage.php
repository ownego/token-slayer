<?php

namespace App\Services\Pricing;

use App\Models\Event;
use App\Services\Events\TurnUsage;

/**
 * The token counts of one turn, in the shape the cost formula needs. Built
 * from a live hook payload at ingest or from a stored event row, so both
 * paths price with the same rules.
 */
final readonly class PricedUsage
{
    /**
     * Event providers whose hook reports model, input and cache tokens and
     * whose vendor prices are known. Every other provider sends output only.
     *
     * @var list<string>
     */
    public const array PRICED_PROVIDERS = ['claude-code', 'codex'];

    /**
     * Hold one turn's token counts for pricing.
     *
     * @param  string  $provider  the `events.provider` value
     * @param  ?string  $model  the turn's primary model id, null when the hook sent none
     * @param  int  $outputTokens  output tokens (Codex output already includes reasoning)
     * @param  int  $inputTokens  input tokens as the vendor reports them (Codex: includes cached input)
     * @param  int  $cacheWriteTokens  tokens written to the prompt cache
     * @param  int  $cacheReadTokens  tokens served from the prompt cache
     * @param  bool  $isSubagent  whether the turn ran in a subagent (Claude Code writes those caches with a 5m TTL)
     */
    public function __construct(
        public string $provider,
        public ?string $model,
        public int $outputTokens,
        public int $inputTokens,
        public int $cacheWriteTokens,
        public int $cacheReadTokens,
        public bool $isSubagent,
    ) {}

    /**
     * Build from a hook payload already parsed at ingest.
     *
     * @param  string  $provider  the `?provider=` the hook posted under
     * @param  ?string  $model  the turn's primary model id
     * @param  TurnUsage  $usage  the parsed token counts
     * @param  ?string  $sessionId  the payload session id
     * @return self
     */
    public static function fromTurn(string $provider, ?string $model, TurnUsage $usage, ?string $sessionId): self
    {
        return new self(
            $provider,
            $model,
            $usage->tokens,
            $usage->inputTokens,
            $usage->cacheCreationInputTokens,
            $usage->cacheReadInputTokens,
            self::isSubagentSession($sessionId),
        );
    }

    /**
     * Build from a stored event row.
     *
     * @param  Event  $event  the event to price
     * @return self
     */
    public static function fromEvent(Event $event): self
    {
        return new self(
            (string) $event->provider,
            $event->model,
            (int) $event->tokens,
            (int) $event->input_tokens,
            (int) $event->cache_creation_input_tokens,
            (int) $event->cache_read_input_tokens,
            self::isSubagentSession($event->session_id),
        );
    }

    /**
     * Whether this provider's events can carry a cost at all.
     *
     * @return bool
     */
    public function isPricedProvider(): bool
    {
        return in_array($this->provider, self::PRICED_PROVIDERS, true);
    }

    /**
     * Whether the hook sent everything a cost needs: a model and some input
     * or cache tokens. A real turn always reads some input, so all-zero means
     * an old or modified hook rather than a free turn.
     *
     * @return bool
     */
    public function hasFullData(): bool
    {
        return $this->model !== null
            && ($this->inputTokens + $this->cacheWriteTokens + $this->cacheReadTokens) > 0;
    }

    /**
     * The hook folds a SubagentStop's agent id into its session id as
     * `parent_session:agent_id`; a main-thread session id has no colon.
     *
     * @param  ?string  $sessionId  the event's session id
     * @return bool
     */
    private static function isSubagentSession(?string $sessionId): bool
    {
        return str_contains($sessionId ?? '', ':');
    }
}
