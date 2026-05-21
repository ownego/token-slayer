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
function currentUserId() {
    const meta = document.querySelector('meta[name="aiorg-user-id"]');
    return meta ? Number(meta.getAttribute('content')) : null;
}

function postToHost(message) {
    if (typeof acquireVsCodeApi === 'function') {
        const api = (window.__aiorgVscodeApi ??= acquireVsCodeApi());
        api.postMessage(message);
    } else if (window.parent !== window) {
        window.parent.postMessage(message, '*');
    }
}

function start() {
    if (!window.Echo) {
        setTimeout(start, 50);
        return;
    }

    const me = currentUserId();
    const channel = window.Echo.channel('battlefield');

    const connector = window.Echo.connector?.pusher?.connection;
    if (connector) {
        connector.bind('connected', () => postToHost({ type: 'connection-state', state: 'connected' }));
        connector.bind('disconnected', () => postToHost({ type: 'connection-state', state: 'disconnected' }));
        connector.bind('connecting', () => postToHost({ type: 'connection-state', state: 'reconnecting' }));
    }

    channel.listen('.HitDealt', (p) => {
        if (me === null || Number(p.user_id) !== me) {
            return;
        }
        postToHost({
            type: 'hit-landed',
            userId: Number(p.user_id),
            damage: Number(p.damage),
            bossId: Number(p.boss_id),
            bossHpAfter: Number(p.boss_hp_after),
            bossMaxHp: Number(p.boss_max_hp),
        });
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

    postToHost({ type: 'connection-state', state: 'connecting' });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
