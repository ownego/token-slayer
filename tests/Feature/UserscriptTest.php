<?php

test('tracker userscript is publicly accessible as javascript', function () {
    $response = $this->get('/tracker.user.js');

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/javascript');
});

test('tracker userscript carries a valid userscript header targeting claude.ai', function () {
    $script = $this->get('/tracker.user.js')->getContent();

    expect($script)
        ->toContain('// ==UserScript==')
        ->toContain('// ==/UserScript==')
        ->toContain('@match        https://claude.ai/*')
        ->toContain('@grant        GM_xmlhttpRequest')
        ->toContain('@grant        GM_getValue')
        ->toContain('@grant        GM_setValue')
        ->toContain('@connect      '.parse_url(url('/'), PHP_URL_HOST));
});

test('tracker userscript posts stop events to the api with the claude-ai provider', function () {
    $script = $this->get('/tracker.user.js')->getContent();

    expect($script)
        ->toContain(url('/api/events').'?provider=claude-ai')
        ->toContain("hook_event_name: 'Stop'")
        ->toContain("'Authorization': 'Bearer ' + token");
});

test('tracker userscript baselines existing history before dealing damage', function () {
    $script = $this->get('/tracker.user.js')->getContent();

    expect($script)
        ->toContain("GM_getValue('baselined_v2', false)")
        ->toContain('if (baselining) {');
});

test('tracker userscript reports only the new tokens of a growing message', function () {
    $script = $this->get('/tracker.user.js')->getContent();

    // seen stores the count already reported, so a streamed reply that grows
    // across syncs reports only the delta instead of being frozen at its
    // first partial length.
    expect($script)
        ->toContain('const counted = seen[message.uuid] || 0;')
        ->toContain('const delta = current - counted;')
        ->toContain('tokens += delta;');
});

test('tracker userscript only commits counted tokens after a confirmed report', function () {
    $script = $this->get('/tracker.user.js')->getContent();

    // A failed report must leave seen/stamps untouched so the same tokens are
    // retried on the next sync rather than silently lost.
    expect($script)
        ->toContain('if (await report(tokens, conversation.uuid)) commit();')
        ->toContain('resolve(res.status >= 200 && res.status < 300);');
});
