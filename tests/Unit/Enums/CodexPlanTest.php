<?php

use App\Enums\CodexPlan;

it('maps known ChatGPT plan_type values to labeled, colored cases', function (string $raw, CodexPlan $expected): void {
    expect(CodexPlan::tryFrom($raw))->toBe($expected)
        ->and($expected->getLabel())->not->toBeEmpty()
        ->and($expected->getColor())->not->toBeEmpty();
})->with([
    ['free', CodexPlan::Free],
    ['plus', CodexPlan::Plus],
    ['pro', CodexPlan::Pro],
    ['team', CodexPlan::Team],
    ['enterprise', CodexPlan::Enterprise],
    ['business', CodexPlan::Business],
]);

it('returns null for tryFrom() on an unrecognized plan_type', function (): void {
    expect(CodexPlan::tryFrom('some-future-plan'))->toBeNull();
});
