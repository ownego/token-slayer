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
    const meta = document.querySelector('meta[name="token-slayer-user-id"]');
    return meta ? Number(meta.getAttribute('content')) : null;
}

function postToHost(message) {
    if (!shouldForwardToHost(message, typeof acquireVsCodeApi === 'function', window.parent !== window)) {
        console.warn('[token-slayer-bridge] postToHost dropped (no host)', message);
        return;
    }
    if (typeof acquireVsCodeApi === 'function') {
        const api = (window.__tokenSlayerVscodeApi ??= acquireVsCodeApi());
        api.postMessage(message);
        console.log('[token-slayer-bridge] sent via vscode api', message);
    } else if (window.parent !== window) {
        window.parent.postMessage(message, '*');
        console.log('[token-slayer-bridge] sent via parent', message);
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

function emitInitialBossSnapshot() {
    const mount = document.getElementById('battlefield-mount');
    const raw = mount?.getAttribute('data-battlefield-state');
    if (!raw) return;
    try {
        const data = JSON.parse(raw);
        const boss = data && data.boss;
        if (!boss) return;
        const me = currentUserId();
        const leaderboard = Array.isArray(data.leaderboard) ? data.leaderboard : [];
        const ownRow = me !== null ? leaderboard.find((row) => Number(row.userId) === me) : null;
        postToHost({
            type: 'boss-snapshot',
            bossId: Number(boss.number ?? boss.id ?? 0),
            name: String(boss.name ?? `Boss ${boss.number ?? ''}`).trim(),
            maxHp: Number(boss.maxHp ?? boss.max_hp ?? 0),
            currentHp: Number(boss.currentHp ?? boss.current_hp ?? 0),
            yourDamage: Number(ownRow?.damage ?? 0),
        });
    } catch (err) {
        console.warn('[token-slayer-bridge] could not parse battlefield-state', err);
    }
}

function start() {
    if (!window.Echo) {
        if (!window.__tokenSlayerBridgeWaitLogged) {
            console.log('[token-slayer-bridge] waiting for window.Echo');
            window.__tokenSlayerBridgeWaitLogged = true;
        }
        setTimeout(start, 50);
        return;
    }

    console.log('[token-slayer-bridge] started', {
        userId: currentUserId(),
        hasVscodeApi: typeof acquireVsCodeApi === 'function',
        hasParent: window.parent !== window,
        connector: window.Echo.connector?.constructor?.name,
    });

    emitInitialBossSnapshot();

    const me = currentUserId();
    const channel = window.Echo.channel('battlefield');

    const connection = window.Echo.connector?.pusher?.connection;
    if (connection) {
        console.log('[token-slayer-bridge] initial pusher state:', connection.state);
        connection.bind('state_change', (states) => {
            console.log('[token-slayer-bridge] pusher state_change:', states);
            postToHost({ type: 'connection-state', state: mapPusherState(states.current) });
        });
        // Pusher may have transitioned to 'connected' before this bind runs;
        // post the current state once so the host doesn't stay on 'connecting'.
        postToHost({ type: 'connection-state', state: mapPusherState(connection.state) });
    } else {
        console.warn('[token-slayer-bridge] no Pusher connection found at Echo.connector.pusher.connection');
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
