<?php

namespace App\Services\Events;

/**
 * One Stop event's usage: the tokens it dealt and how they split across the
 * models that produced them. The split arrives from the hook, which reads the
 * transcript on the machine that owns it — the server never opens a transcript.
 */
final readonly class TurnUsage
{
    /**
     * @param  int  $tokens  total output tokens the turn produced -- the only field damage is dealt from
     * @param  array<string, int>  $modelTokens  sanitized raw model id => tokens
     * @param  int  $inputTokens  fresh (non-cached) input tokens the turn consumed, for analytics only
     * @param  int  $cacheCreationInputTokens  input tokens spent writing to the prompt cache, for analytics only
     * @param  int  $cacheReadInputTokens  input tokens served from the prompt cache, for analytics only
     */
    public function __construct(
        public int $tokens,
        public array $modelTokens,
        public int $inputTokens = 0,
        public int $cacheCreationInputTokens = 0,
        public int $cacheReadInputTokens = 0,
    ) {}

    /**
     * Build the usage for a Stop event from its already-received payload.
     * Returns a zero-token instance when the client sent nothing usable, which
     * is the not-yet-updated-client path rather than an error.
     *
     * @param  array<string, mixed>  $payload  the raw hook payload
     * @param  ModelUsageParser  $parser
     * @return self
     */
    public static function fromPayload(array $payload, ModelUsageParser $parser): self
    {
        $tokens = (int) ($payload['tokens'] ?? 0);

        return new self(
            max($tokens, 0),
            $parser->sanitize($payload['models'] ?? null),
            max((int) ($payload['input_tokens'] ?? 0), 0),
            max((int) ($payload['cache_creation_input_tokens'] ?? 0), 0),
            max((int) ($payload['cache_read_input_tokens'] ?? 0), 0),
        );
    }
}
