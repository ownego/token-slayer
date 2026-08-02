<?php

use App\Services\InstallCommandPresenter;

test('envVar uppercases the namespace and appends _TOKEN', function () {
    expect((new InstallCommandPresenter('acme', 'x'))->envVar())->toBe('ACME_TOKEN');
});

test('tokenPath places the token under the namespaced config directory', function () {
    expect((new InstallCommandPresenter('acme', 'x'))->tokenPath())
        ->toBe('~/.config/acme/token');
});

test('cliUnix builds a curl-pipe-sh command carrying the token env var', function () {
    $presenter = new InstallCommandPresenter('token_slayer', 'plain-abc');

    expect($presenter->cliUnix('https://example.test/install'))
        ->toBe('curl -fsSL https://example.test/install | TOKEN_SLAYER_TOKEN=plain-abc sh');
});

test('cliWindowsPowerShell sets the env var before the irm|iex pipeline', function () {
    $presenter = new InstallCommandPresenter('token_slayer', 'plain-abc');

    expect($presenter->cliWindowsPowerShell('https://example.test/install.ps1'))
        ->toBe('$env:TOKEN_SLAYER_TOKEN=\'plain-abc\'; irm https://example.test/install.ps1 | iex');
});

test('cliWindowsCmd wraps the powershell form so cmd.exe can run it', function () {
    $presenter = new InstallCommandPresenter('token_slayer', 'plain-abc');

    expect($presenter->cliWindowsCmd('https://example.test/install.ps1'))
        ->toBe('powershell -ExecutionPolicy ByPass -c "$env:TOKEN_SLAYER_TOKEN=\'plain-abc\'; irm https://example.test/install.ps1 | iex"');
});

test('cowork builds a curl-pipe-sh command against the cowork install url', function () {
    $presenter = new InstallCommandPresenter('token_slayer', 'plain-abc');

    expect($presenter->cowork('https://example.test/install-cowork'))
        ->toBe('curl -fsSL https://example.test/install-cowork | TOKEN_SLAYER_TOKEN=plain-abc sh');
});

test('tokenSave makes the config dir, writes the token, and locks its permissions', function () {
    $presenter = new InstallCommandPresenter('token_slayer', 'plain-abc');

    expect($presenter->tokenSave())->toBe(
        "mkdir -p ~/.config/token_slayer && printf '%s' 'plain-abc' > ~/.config/token_slayer/token && chmod 600 ~/.config/token_slayer/token"
    );
});
