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

test('install-cowork is a standalone shell installer that schedules the watcher', function () {
    $response = $this->get('/install-cowork');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/x-shellscript');

    $script = $response->getContent();
    expect($script)
        ->toContain('#!/bin/sh')
        ->toContain('cowork-watcher.py')
        ->toContain(route('cowork-watcher'))
        ->toContain('LaunchAgents/token_slayer.cowork.plist')  // macOS launchd
        ->toContain('token_slayer-cowork.timer')               // Linux systemd
        ->toContain('# token_slayer-cowork')                   // Linux cron fallback
        ->toContain('${TOKEN_SLAYER_TOKEN:-}');                // saves token like /install
});

test('install-cowork does not install terminal hooks', function () {
    $script = $this->get('/install-cowork')->getContent();

    // Cowork is independent: no Claude Code / Codex / Antigravity hook wiring.
    expect($script)
        ->not->toContain('.claude/settings.json')
        ->not->toContain('.codex/config.toml')
        ->not->toContain('.gemini/config/hooks.json');
});

test('the main install.sh no longer bundles the cowork watcher', function () {
    expect($this->get('/install')->getContent())->not->toContain('cowork-watcher.py');
});

test('cowork artifacts use the configured hook namespace', function () {
    config(['app.hook_namespace' => 'acme']);

    $watcher = $this->get('/cowork-watcher.py')->getContent();
    $install = $this->get('/install-cowork')->getContent();

    expect($watcher)->toContain('~/.config/acme/token')
        ->and($watcher)->toContain('provider=cowork')
        ->and($install)->toContain('acme.cowork.plist')
        ->and($install)->not->toContain('token_slayer');
});
