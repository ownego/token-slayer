<?php

use App\Enums\GrantStatus;

it('exposes the three grant lifecycle states with labels and colors', function () {
    expect(GrantStatus::Pending->value)->toBe('pending')
        ->and(GrantStatus::Claimed->value)->toBe('claimed')
        ->and(GrantStatus::Revoked->value)->toBe('revoked')
        ->and(GrantStatus::Pending->getLabel())->toBe('Pending')
        ->and(GrantStatus::Claimed->getColor())->toBe('success')
        ->and(GrantStatus::Revoked->getColor())->toBe('danger');
});
