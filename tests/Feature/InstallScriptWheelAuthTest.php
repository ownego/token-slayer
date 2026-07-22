<?php

use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['app.hook_namespace' => 'token_slayer']));

test('the wheel download sends a bearer token and branches on status', function () {
    // The resolver is read during render for clientVersion; keep it offline to
    // prove the script still renders when GitHub is down.
    Http::fake(['api.github.com/*' => Http::response(['message' => 'x'], 500)]);

    $script = $this->get('/install')->assertOk()->getContent();

    expect($script)
        ->toContain('${TOKEN_SLAYER_TOKEN:-}')                 // env var, namespace-derived
        ->toContain('.config/token_slayer/token')              // saved-token fallback
        ->toContain('Authorization: Bearer $SLAYER_TOKEN')     // sent on the download
        ->toContain("'%{http_code}'")                          // status captured
        ->toContain('Regenerate token')                        // 401 branch guidance
        ->toContain('could not download the CLI');             // generic branch
});

test('the rendered install script is valid POSIX shell', function () {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'x'], 500)]);

    $script = $this->get('/install')->assertOk()->getContent();

    $path = tempnam(sys_get_temp_dir(), 'slayer-install-').'.sh';
    file_put_contents($path, $script);

    exec('sh -n '.escapeshellarg($path).' 2>&1', $output, $exit);
    @unlink($path);

    expect($exit)->toBe(0, "sh -n reported syntax errors:\n".implode("\n", $output));
});
