<?php

use App\Enums\AccountStatus;
use App\Models\Account;
use App\Notifications\AccountTokenRejectedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;

uses(RefreshDatabase::class);

test('the slack message carries identity and reason but no token material', function () {
    $account = Account::factory()->connected()->create([
        'status' => AccountStatus::NeedsReauth,
        'email' => 'leaked@example.com',
        'oauth_access_token' => 'sk-ant-oat01-SECRETACCESS',
        'oauth_refresh_token' => 'sk-ant-ort01-SECRETREFRESH',
    ]);

    $message = (new AccountTokenRejectedNotification($account, 'invalid_grant'))
        ->toSlack(new AnonymousNotifiable);

    $payload = json_encode($message->toArray());

    expect($payload)
        ->toContain('leaked@example.com')
        ->toContain('invalid_grant')
        ->not->toContain('SECRETACCESS')
        ->not->toContain('SECRETREFRESH')
        ->not->toContain('sk-ant-');
});

test('each section field uses a real newline, not a literal backslash-n', function () {
    $account = Account::factory()->needsReauth()->create();

    $message = (new AccountTokenRejectedNotification($account, 'invalid_grant'))
        ->toSlack(new AnonymousNotifiable);

    $section = collect($message->toArray()['blocks'])->firstWhere('type', 'section');

    expect($section['fields'])->not->toBeEmpty();
    foreach ($section['fields'] as $field) {
        expect($field['type'])->toBe('mrkdwn')
            ->and($field['text'])->toContain("\n")   // a real newline
            ->and($field['text'])->not->toContain('\n'); // never the literal backslash-n
    }
});

test('it delivers on the slack channel', function () {
    $account = Account::factory()->needsReauth()->create();

    $notification = new AccountTokenRejectedNotification($account, 'unauthorized');

    expect($notification->via(new AnonymousNotifiable))->toBe(['slack']);
});
