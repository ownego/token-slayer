// ==UserScript==
// @name         Token Slayer claude.ai tracker
// @namespace    {{ $appUrl }}
// @version      1.0.0
// @description  Reports estimated token usage from claude.ai (and synced Claude Desktop chats) to Token Slayer as boss damage.
// @match        https://claude.ai/*
// @grant        GM_xmlhttpRequest
// @grant        GM_getValue
// @grant        GM_setValue
// @grant        unsafeWindow
// @connect      {{ $appHost }}
// @noframes
// ==/UserScript==

(function () {
    'use strict';

    const EVENTS_URL = '{{ $eventsUrl }}';
    const SYNC_INTERVAL_MS = 60 * 1000;
    const CONVERSATION_LIMIT = 20;
    const MAX_SEEN = 5000;

    // Tokens are estimated from visible text (~4 chars per token) because
    // claude.ai exposes no usage numbers; thinking/system tokens are invisible.
    function estimateTokens(text) {
        return Math.ceil(text.length / 4);
    }

    function getToken() {
        let token = GM_getValue('hook_token', '');
        if (!token) {
            token = (prompt('Token Slayer: paste your hook token (Profile page -> Regenerate token)') || '').trim();
            if (token) GM_setValue('hook_token', token);
        }
        return token;
    }

    async function claudeApi(path) {
        const res = await fetch(path, {
            headers: { accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error('claude.ai API ' + res.status + ' for ' + path);
        return res.json();
    }

    let orgId = null;
    async function getOrgId() {
        if (orgId) return orgId;
        const orgs = await claudeApi('/api/organizations');
        const chatOrg = orgs.find((o) => (o.capabilities || []).includes('chat')) || orgs[0];
        if (!chatOrg) throw new Error('no claude.ai organization found');
        orgId = chatOrg.uuid;
        return orgId;
    }

    function messageText(message) {
        if (typeof message.text === 'string' && message.text) return message.text;
        return (message.content || [])
            .map((block) => (typeof block.text === 'string' ? block.text : ''))
            .join('');
    }

    // Resolves true only when the report was accepted (2xx). On any failure
    // the caller leaves the messages uncounted so the same tokens are retried
    // on the next sync instead of being silently lost.
    function report(tokens, conversationId) {
        return new Promise(function (resolve) {
            const token = getToken();
            if (!token) {
                resolve(false);
                return;
            }
            GM_xmlhttpRequest({
                method: 'POST',
                url: EVENTS_URL,
                headers: {
                    'Authorization': 'Bearer ' + token,
                    'Content-Type': 'application/json',
                },
                data: JSON.stringify({
                    hook_event_name: 'Stop',
                    session_id: conversationId,
                    tokens: tokens,
                }),
                onload: function (res) {
                    if (res.status === 401) {
                        GM_setValue('hook_token', '');
                        console.warn('[token-slayer] token rejected — you will be asked again on the next sync');
                        resolve(false);
                        return;
                    }
                    resolve(res.status >= 200 && res.status < 300);
                },
                onerror: function () { resolve(false); },
                ontimeout: function () { resolve(false); },
            });
        });
    }

    function pruneSeen(seen) {
        const ids = Object.keys(seen);
        if (ids.length <= MAX_SEEN) return seen;
        const pruned = {};
        for (const id of ids.slice(ids.length - Math.floor(MAX_SEEN / 2))) {
            pruned[id] = seen[id];
        }
        return pruned;
    }

    let syncing = false;
    async function sync() {
        if (syncing) return;
        syncing = true;
        try {
            const org = await getOrgId();
            const listing = await claudeApi('/api/organizations/' + org + '/chat_conversations?limit=' + CONVERSATION_LIMIT);
            const conversations = Array.isArray(listing) ? listing : (listing.data || []);
            // seen maps an assistant message uuid to the token count already
            // reported for it. Storing the count (not just a boolean) lets a
            // streamed reply that grows across syncs report only its new tokens
            // instead of being permanently frozen at its first partial length.
            const seen = GM_getValue('seen_messages_v2', {});
            const stamps = GM_getValue('conversation_stamps_v2', {});
            // First run only baselines: everything already in the account is
            // marked seen without dealing damage, so installing the script
            // doesn't dump months of history onto the boss at once.
            const baselining = !GM_getValue('baselined_v2', false);

            for (const conversation of conversations) {
                if (stamps[conversation.uuid] === conversation.updated_at) continue;

                const detail = await claudeApi('/api/organizations/' + org + '/chat_conversations/' + conversation.uuid + '?tree=True&rendering_mode=messages');
                let tokens = 0;
                // Collect the new counts so they are only committed to `seen`
                // once the report for this conversation has been accepted.
                const counts = [];
                for (const message of detail.chat_messages || []) {
                    if (message.sender !== 'assistant') continue;
                    const current = estimateTokens(messageText(message));
                    const counted = seen[message.uuid] || 0;
                    const delta = current - counted;
                    if (delta <= 0) continue;
                    tokens += delta;
                    counts.push({ uuid: message.uuid, count: current });
                }

                const commit = function () {
                    for (const entry of counts) {
                        seen[entry.uuid] = entry.count;
                    }
                    stamps[conversation.uuid] = conversation.updated_at;
                };

                if (baselining) {
                    // Mark the existing history seen at its current length
                    // without dealing damage.
                    commit();
                } else if (tokens > 0) {
                    // Advance the stamp/counts only on a confirmed report so a
                    // failed send is retried on the next sync, never dropped.
                    if (await report(tokens, conversation.uuid)) commit();
                } else {
                    // The conversation changed (e.g. a new user message) but
                    // added no assistant tokens — advance the stamp so we don't
                    // re-fetch it until it changes again.
                    commit();
                }
            }

            GM_setValue('seen_messages_v2', pruneSeen(seen));
            GM_setValue('conversation_stamps_v2', stamps);
            GM_setValue('baselined_v2', true);
        } catch (err) {
            console.debug('[token-slayer] sync skipped:', err);
        } finally {
            syncing = false;
        }
    }

    // Near-real-time trigger: watch the page's own completion requests and
    // sync shortly after one finishes. The fetch promise resolves on headers
    // while the reply still streams, so sync again later to catch long
    // replies; anything missed lands on the next interval sync.
    let quickTimer = null;
    let lateTimer = null;
    function scheduleSync() {
        clearTimeout(quickTimer);
        clearTimeout(lateTimer);
        quickTimer = setTimeout(sync, 5000);
        lateTimer = setTimeout(sync, 30000);
    }

    const pageWindow = typeof unsafeWindow === 'undefined' ? window : unsafeWindow;
    const pageFetch = pageWindow.fetch;
    pageWindow.fetch = function () {
        const result = pageFetch.apply(this, arguments);
        const target = arguments[0];
        const url = typeof target === 'string' ? target : (target && target.url) || '';
        if (url.includes('/completion')) {
            result.finally(scheduleSync);
        }
        return result;
    };

    setTimeout(sync, 5000);
    setInterval(sync, SYNC_INTERVAL_MS);
})();
