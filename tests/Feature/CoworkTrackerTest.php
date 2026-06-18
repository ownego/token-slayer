<?php

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('cowork watcher is publicly accessible as a python script', function () {
    $response = $this->get('/cowork-watcher.py');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/x-python');
});

test('cowork watcher posts stop events to the api with the cowork provider', function () {
    $script = $this->get('/cowork-watcher.py')->getContent();

    expect($script)
        ->toContain('#!/usr/bin/env python3')
        ->toContain(url('/api/events').'?provider=cowork')
        ->toContain('"hook_event_name": "Stop"')
        ->toContain('"Authorization": "Bearer " + token');
});

test('cowork watcher reads exact output tokens from agent-mode transcripts', function () {
    $script = $this->get('/cowork-watcher.py')->getContent();

    expect($script)
        ->toContain('local-agent-mode-sessions')
        ->toContain('.claude')
        ->toContain('projects')
        ->toContain('output_tokens')
        ->toContain('"assistant"');
});

test('cowork watcher resolves the transcript directory per operating system', function () {
    $script = $this->get('/cowork-watcher.py')->getContent();

    expect($script)
        ->toContain('Application Support')  // macOS (joined from "Library", "Application Support")
        ->toContain('.config')              // Linux
        ->toContain('APPDATA');             // Windows
});

test('cowork watcher baselines existing transcripts before dealing damage', function () {
    $script = $this->get('/cowork-watcher.py')->getContent();

    expect($script)
        ->toContain('_baselined')
        ->toContain('if not baselining and tokens > 0:');
});

test('cowork watcher reads the token from the shared hook token file', function () {
    $script = $this->get('/cowork-watcher.py')->getContent();

    expect($script)->toContain('~/.config/token_slayer/token');
});

test('install.sh downloads the cowork watcher and schedules it on macOS and Linux', function () {
    $script = $this->get('/install')->getContent();

    expect($script)
        ->toContain('cowork-watcher.py')
        ->toContain(route('cowork-watcher'))
        ->toContain('LaunchAgents/token_slayer.cowork.plist')  // macOS launchd
        ->toContain('token_slayer-cowork.timer')               // Linux systemd
        ->toContain('# token_slayer-cowork');                  // Linux cron fallback
});

test('cowork artifacts use the configured hook namespace', function () {
    config(['app.hook_namespace' => 'acme']);

    $watcher = $this->get('/cowork-watcher.py')->getContent();
    $install = $this->get('/install')->getContent();

    expect($watcher)->toContain('~/.config/acme/token')
        ->and($watcher)->toContain('provider=cowork')
        ->and($install)->toContain('acme.cowork.plist')
        ->and($install)->not->toContain('token_slayer');
});
