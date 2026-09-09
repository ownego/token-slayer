<?php

use App\Enums\Provider;

it('has a label and color for both cases', function (Provider $case): void {
    expect($case->getLabel())->not->toBeEmpty()
        ->and($case->getColor())->not->toBeEmpty();
})->with([Provider::Claude, Provider::Codex]);

it('is backed by the exact accounts.provider string values already stored on disk', function (): void {
    expect(Provider::Claude->value)->toBe('claude')
        ->and(Provider::Codex->value)->toBe('codex');
});
