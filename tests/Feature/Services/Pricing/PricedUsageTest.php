<?php

use App\Models\Event;
use App\Models\ModelPrice;
use App\Services\Pricing\PricedUsage;

it('marks a subagent event by the agent id folded into its session id', function (?string $sessionId, bool $expected) {
    $event = Event::factory()->make(['session_id' => $sessionId, 'provider' => 'claude-code']);

    expect(PricedUsage::fromEvent($event)->isSubagent)->toBe($expected);
})->with([
    'subagent' => ['abc:agent1', true],
    'main thread' => ['abc', false],
    'no session' => [null, false],
]);

it('has full data only with a model and some input or cache tokens', function (?string $model, int $input, int $write, int $read, bool $expected) {
    $usage = new PricedUsage('claude-code', $model, 10, $input, $write, $read, false);

    expect($usage->hasFullData())->toBe($expected);
})->with([
    'no model' => [null, 100, 0, 0, false],
    'no input or cache' => ['claude-sonnet-5-5', 0, 0, 0, false],
    'input only' => ['claude-sonnet-5-5', 100, 0, 0, true],
    'cache read only' => ['claude-sonnet-5-5', 0, 0, 100, true],
]);

it('prices only claude code and codex events', function (string $provider, bool $expected) {
    expect((new PricedUsage($provider, 'm', 1, 1, 0, 0, false))->isPricedProvider())->toBe($expected);
})->with([
    'claude-code' => ['claude-code', true],
    'codex' => ['codex', true],
    'claude-ai' => ['claude-ai', false],
    'cowork' => ['cowork', false],
    'antigravity' => ['antigravity', false],
]);

it('builds from an event row', function () {
    $event = Event::factory()->make([
        'provider' => 'codex',
        'model' => 'gpt-6-sol',
        'tokens' => 20,
        'input_tokens' => 300,
        'cache_creation_input_tokens' => 0,
        'cache_read_input_tokens' => 200,
        'session_id' => 's1',
    ]);

    $usage = PricedUsage::fromEvent($event);

    expect($usage)->toEqual(new PricedUsage('codex', 'gpt-6-sol', 20, 300, 0, 200, false));
});

it('normalises a dated model id to its undated candidate', function (string $id, array $expected) {
    expect(ModelPrice::candidateIds($id))->toBe($expected);
})->with([
    'dated' => ['claude-haiku-4-5-20251001', ['claude-haiku-4-5-20251001', 'claude-haiku-4-5']],
    'undated' => ['gpt-6-sol', ['gpt-6-sol']],
]);
