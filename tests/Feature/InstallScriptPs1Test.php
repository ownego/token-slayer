<?php

use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('install.ps1 is publicly accessible as plain text', function () {
    $response = $this->get(route('install-script-ps1'));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/plain');
});

test('install.ps1 renders every blade directive away', function () {
    // The body is wrapped in @verbatim so PowerShell's own $vars survive; a
    // mistake there ships raw blade to the user instead of a runnable script.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->not->toContain('@php')
        ->not->toContain('@endphp')
        ->not->toContain('@verbatim')
        ->not->toContain('@endverbatim')
        ->not->toContain('{{')
        ->not->toContain('{!!');
});

test('install.ps1 stamps the namespace into the header variables', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain("\$Ns            = 'token_slayer'")
        ->toContain("\$EnvVarName    = 'TOKEN_SLAYER_TOKEN'")
        ->toContain(url('/api/events'));
});

test('install.ps1 points its own update URL back at itself, not at the POSIX installer', function () {
    // `token-slayer update` re-fetches $InstallUrl. On Windows that must be the
    // PowerShell script — pointing at /install would hand a Windows box a sh
    // script it cannot run.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("\$InstallUrl    = '".route('install-script-ps1')."'");
});

test('install.ps1 requires a Python that has a working venv and pyexpat', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('import sys,venv,pyexpat')
        ->toContain('py -3.12')
        ->toContain('py -3.10');
});

test('install.ps1 skips the Microsoft Store python stub', function () {
    // A bare `python` that resolves into WindowsApps is the Store alias: it
    // opens the Store instead of running, so it must never be selected.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain('WindowsApps');
});

test('install.ps1 reads the Find-Python pair by index rather than destructuring it', function () {
    // Regression guard. Find-Python returns `,@($exe, $arg)` — one object, on
    // purpose. `$PyExe, $PyArg = Find-Python` therefore bound the whole array
    // to $PyExe, and `& $PyExe` stringified it into the literal command name
    // "py -3", which broke the install on every host with the py launcher.
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('$Py    = Find-Python')
        ->toContain('$PyExe = $Py[0]')
        ->toContain('$PyArg = $Py[1]')
        ->not->toContain('$PyExe, $PyArg = Find-Python');
});

test('install.ps1 installs all three command shims', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("foreach (\$n in 'tok','slayer','token-slayer')");
});

test('install.ps1 authenticates the wheel download with a bearer token and never embeds one', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('Authorization = "Bearer $slayerToken"')
        ->toContain(route('slayer-wheel'));

    // The token is supplied at run time (env var or token file) — a served
    // script that carried one would leak it to everyone who fetches the URL.
    expect($script)->not->toMatch('/sk-ant-[A-Za-z0-9_-]+/');
});

test('install.ps1 routes the hooks through bash for Git-for-Windows users', function () {
    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)
        ->toContain('"shell": "bash"')
        ->toContain('Git for Windows');
});

it('stamps the resolver-derived client version into install.ps1', function () {
    config(['github.token' => 'ghp_test', 'github.cli_repo' => 'acme/slayer-cli']);
    Http::fake(['api.github.com/*' => Http::response([
        'tag_name' => 'v1.2.3',
        'assets' => [['id' => 1, 'name' => 'slayer_cli-latest.whl']],
    ], 200)]);

    $script = $this->get(route('install-script-ps1'))->content();

    expect($script)->toContain("\$ClientVersion = '1.2.3'");
});

it('stamps an empty client version into install.ps1 when the release cannot be resolved', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'down'], 500)]);

    $script = $this->get(route('install-script-ps1'))->content();

    // Fail-soft: an empty stamp still yields a runnable script.
    expect($script)->toContain("\$ClientVersion = ''");
});
