/**
 * VSCode webview iframe → extension host bridge.
 *
 * Subscribes to the public `battlefield` channel and forwards a whitelist
 * of events to the extension host via the webview API. Events scoped to
 * the current user (charging, hits, idle) are pre-filtered so the host
 * only sees what is relevant to the IDE user.
 *
 * Field names below mirror the broadcastWith() payloads of the
 * corresponding app/Events/*.php classes at the time of writing.
 */
import { shouldForwardToHost, packHit } from './ide-bridge-internal.js';

function currentUserId() {
    const meta = document.querySelector('meta[name="aiorg-user-id"]');
    return meta ? Number(meta.getAttribute('content')) : null;
}

function postToHost(message) {
    if (!shouldForwardToHost(message, typeof acquireVsCodeApi === 'function', window.parent !== window)) {
        return;
    }
    if (typeof acquireVsCodeApi === 'function') {
        const api = (window.__aiorgVscodeApi ??= acquireVsCodeApi());
        api.postMessage(message);
    } else if (window.parent !== window) {
        window.parent.postMessage(message, '*');
    }
}

function mapPusherState(state) {
    // Pusher states: initialized, connecting, connected, unavailable, failed, disconnected
    switch (state) {
        case 'connected':
            return 'connected';
        case 'disconnected':
        case 'failed':
            return 'disconnected';
        case 'unavailable':
        case 'connecting':
        case 'initialized':
        default:
            return 'reconnecting';
    }
}

function start() {
    if (!window.Echo) {
        setTimeout(start, 50);
        return;
    }

    const me = currentUserId();
    const channel = window.Echo.channel('battlefield');

    const connection = window.Echo.connector?.pusher?.connection;
    if (connection) {
        connection.bind('state_change', (states) => {
            postToHost({ type: 'connection-state', state: mapPusherState(states.current) });
        });
        // Pusher may have transitioned to 'connected' before this bind runs;
        // post the current state once so the host doesn't stay on 'connecting'.
        postToHost({ type: 'connection-state', state: mapPusherState(connection.state) });
    }

    channel.listen('.HitDealt', (p) => {
        const out = packHit(p, currentUserId());
        if (out) {
            postToHost(out);
        }
    });

    channel.listen('.BossKilled', (p) => {
        postToHost({
            type: 'boss-defeated',
            bossId: Number(p.boss_id),
            killerUserId: Number(p.killer_user_id),
            killerHandle: p.killer_slack_handle ?? null,
        });
    });

    channel.listen('.BossSpawned', (p) => {
        postToHost({
            type: 'boss-spawned',
            bossId: Number(p.boss_id),
            name: String(p.boss_name ?? ''),
            maxHp: Number(p.max_hp ?? 0),
        });
    });

    channel.listen('.FighterCharging', (p) => {
        if (me === null || Number(p.user_id) !== me) {
            return;
        }
        postToHost({
            type: 'charging-updated',
            userId: Number(p.user_id),
            activity: p.activity ?? null,
            startedAt: null,
        });
    });

    channel.listen('.FighterIdled', (p) => {
        if (me === null || Number(p.user_id) !== me) {
            return;
        }
        postToHost({
            type: 'charging-updated',
            userId: Number(p.user_id),
            activity: null,
            startedAt: null,
        });
    });

    // If the connector isn't available, the host stays on its previous state
    // (typically 'connecting' from the templates' initial render).
    if (!connection) {
        postToHost({ type: 'connection-state', state: 'connecting' });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
