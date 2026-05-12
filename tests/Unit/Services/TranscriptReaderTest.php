<?php

use App\Services\TranscriptReader;

beforeEach(function () {
    $this->reader = new TranscriptReader;
    $this->path = tempnam(sys_get_temp_dir(), 'transcript-');
});

afterEach(function () {
    @unlink($this->path);
});

function writeTranscript(string $path, array $entries): void
{
    $lines = array_map(fn (array $e) => json_encode($e), $entries);
    file_put_contents($path, implode("\n", $lines)."\n");
}

test('returns 0 when the file is missing', function () {
    expect($this->reader->latestTurnOutputTokens('/nonexistent/transcript.jsonl'))->toBe(0);
});

test('returns 0 when no assistant entries exist', function () {
    writeTranscript($this->path, [
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'hi']]]],
    ]);

    expect($this->reader->latestTurnOutputTokens($this->path))->toBe(0);
});

test('returns the last assistant output_tokens for a single-message turn', function () {
    writeTranscript($this->path, [
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'hi']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 405]]],
    ]);

    expect($this->reader->latestTurnOutputTokens($this->path))->toBe(405);
});

test('sums assistant output_tokens across a multi-step turn with tool calls', function () {
    writeTranscript($this->path, [
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'previous prompt']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 999]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'text', 'text' => 'this turn']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 50]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 70]]],
        ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'ok']]]],
        ['type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 30]]],
    ]);

    expect($this->reader->latestTurnOutputTokens($this->path))->toBe(150);
});

test('skips malformed lines without throwing', function () {
    file_put_contents($this->path, "{not json\n".json_encode([
        'type' => 'assistant', 'message' => ['usage' => ['output_tokens' => 42]],
    ])."\n");

    expect($this->reader->latestTurnOutputTokens($this->path))->toBe(42);
});
