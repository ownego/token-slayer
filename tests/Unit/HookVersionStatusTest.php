<?php

use App\Models\User;
use App\Support\HookVersionStatus;

test('needs no manual nudge when the hook already reports the latest version', function () {
    $user = User::factory()->make(['hook_version' => '7']);

    expect(HookVersionStatus::needsManualNudge($user, '7'))->toBeFalse();
});

test('needs no manual nudge when the hook is behind but can report a version at all', function () {
    // Any hook that sends `hook_version` already carries the self-heal
    // capture logic, so it upgrades itself on the developer's next
    // SessionStart -- nagging them to run a command they do not need to run
    // is noise, not a nudge.
    $user = User::factory()->make(['hook_version' => '6']);

    expect(HookVersionStatus::needsManualNudge($user, '7'))->toBeFalse();
});

test('needs a manual nudge when the hook is too old to report its own version', function () {
    // A pre-5 hook never sends `hook_version`, so it cannot self-heal --
    // this is the one population the nudge exists for.
    $user = User::factory()->make(['hook_version' => null, 'client_version' => '1.0.0']);

    expect(HookVersionStatus::needsManualNudge($user, '7'))->toBeTrue();
});

test('needs no manual nudge for a user who has never sent an event', function () {
    $user = User::factory()->make(['hook_version' => null, 'client_version' => null]);

    expect(HookVersionStatus::needsManualNudge($user, '7'))->toBeFalse();
});

test('needs no manual nudge when there is no signed-in user', function () {
    expect(HookVersionStatus::needsManualNudge(null, '7'))->toBeFalse();
});
