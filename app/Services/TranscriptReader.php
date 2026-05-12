<?php

namespace App\Services;

class TranscriptReader
{
    /**
     * Sum of output_tokens across all assistant entries that belong to the
     * latest turn in a Claude Code JSONL transcript.
     *
     * A "turn" walks back from the end of the file, accumulating every
     * `assistant` entry's `message.usage.output_tokens`, and stops at the
     * first real user entry (i.e. a `user` entry whose content is not a
     * `tool_result` wrapper from the previous tool call). Returns 0 if the
     * file is unreadable or contains no assistant entries.
     */
    public function latestTurnOutputTokens(string $path): int
    {
        if (! is_readable($path)) {
            return 0;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return 0;
        }

        try {
            $entries = [];
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (is_array($entry) && isset($entry['type'])) {
                    $entries[] = $entry;
                }
            }
        } finally {
            fclose($handle);
        }

        $tokens = 0;
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $entry = $entries[$i];

            if ($entry['type'] === 'assistant') {
                $tokens += (int) ($entry['message']['usage']['output_tokens'] ?? 0);

                continue;
            }

            if ($entry['type'] === 'user' && ! $this->isToolResultWrapper($entry)) {
                break;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function isToolResultWrapper(array $entry): bool
    {
        $content = $entry['message']['content'] ?? null;

        return is_array($content)
            && isset($content[0]['type'])
            && $content[0]['type'] === 'tool_result';
    }
}
