<?php

use App\Enums\AccountStatus;
use App\Models\ClaudeCredential;
use App\Models\CodexCredential;
use App\Models\Contracts\CredentialsProvider;

it('ClaudeCredential implements CredentialsProvider and reads its own columns', function (): void {
    $probedAt = now();
    $credential = new ClaudeCredential([
        'status' => AccountStatus::NeedsReauth,
        'last_probed_at' => $probedAt,
        'probe_error' => 'boom',
    ]);

    expect($credential)->toBeInstanceOf(CredentialsProvider::class)
        ->and($credential->credentialStatus())->toBe(AccountStatus::NeedsReauth)
        ->and($credential->credentialLastProbedAt()->timestamp)->toBe($probedAt->timestamp)
        ->and($credential->credentialProbeError())->toBe('boom');
});

it('CodexCredential implements CredentialsProvider and reads its own columns', function (): void {
    $probedAt = now();
    $credential = new CodexCredential([
        'status' => AccountStatus::Disabled,
        'last_probed_at' => $probedAt,
        'probe_error' => 'kaboom',
    ]);

    expect($credential)->toBeInstanceOf(CredentialsProvider::class)
        ->and($credential->credentialStatus())->toBe(AccountStatus::Disabled)
        ->and($credential->credentialLastProbedAt()->timestamp)->toBe($probedAt->timestamp)
        ->and($credential->credentialProbeError())->toBe('kaboom');
});
