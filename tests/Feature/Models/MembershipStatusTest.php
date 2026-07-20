<?php

use App\Enums\MembershipStatus;

it('has the untracked, tracked, and pending cases', function () {
    expect(MembershipStatus::cases())->toHaveCount(3);
    expect(collect(MembershipStatus::cases())->map->value->all())
        ->toBe(['untracked', 'tracked', 'pending']);
});

it('resolves the pending label and color', function () {
    expect(MembershipStatus::Pending->getLabel())->toBe('Pending setup');
    expect(MembershipStatus::Pending->getColor())->toBe('warning');
});

it('leaves the existing tracked and untracked labels and colors unchanged', function () {
    expect(MembershipStatus::Tracked->getLabel())->toBe('Tracked');
    expect(MembershipStatus::Tracked->getColor())->toBe('success');
    expect(MembershipStatus::Untracked->getLabel())->toBe('Untracked');
    expect(MembershipStatus::Untracked->getColor())->toBe('gray');
});
