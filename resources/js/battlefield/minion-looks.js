import { MINION_TYPES, TIMINGS } from '@battlefield/config.js';
import {
  customMinionType, customSpecsOf, defaultTypesOf, isLookInPool, minionSpriteFrame, pickMinionType, pickSwapType, spawnLookDecision,
} from '@battlefield/minion-custom.js';
import { loadStackedType } from '@battlefield/fighter/custom-fighter-loader.js';
import { CUSTOM_RETRY_MS } from '@battlefield/fighter/custom-fighter-requests.js';
import { releaseCustomFighter } from '@battlefield/fighter/custom-fighter.js';

/**
 * What `MinionLooks` asks of the swarm that owns it.
 *
 * @typedef {object} MinionLooksHost
 * @property {function(number|string): Array<object>} minionsOf The minions a player has on the field.
 * @property {function(number|string, object, object): void} beginSummon Starts the summon of a minion that just got its first look.
 * @property {function(object, object, function(): void): void} playSwapPoof Plays the poof of a look swap where the minion is (it keeps walking meanwhile) and calls back when it ends.
 */

/**
 * Owns which looks the minion swarm shows: loads the custom looks of each player's pool (with
 * their retries), holds back a spawn until they are in, draws a look from the pool for a new
 * minion, swaps the look of minions already out when the pool changes, and takes the
 * textures out of the game once nothing shows them. `Minions` creates it and owns it; the
 * movement, fights, summon and tool rings stay there. Purely cosmetic, like the swarm.
 *
 * Invariants:
 * - A look in the pool stays loaded whether or not minions are out, so the next burst of
 *   subagents finds it in. It is released when it leaves the pool (once no minion shows or
 *   has parked it) or when its fighter leaves the field.
 * - A failed look retries on its own timer, only while the player has minions out.
 * - A minion waiting for its first look is never swapped: its wait ends by `spawnLookDecision`.
 * - A load that ends after `destroy()` or during a scene shutdown touches no state; it drops
 *   the texture it added unless the scene's fresh manager already asked for the same look.
 * - `destroy()` keeps the textures of a player whose swarm is out (a restarted manager
 *   rebuilds it on the same ones) and lets the others go.
 */
export class MinionLooks {
  /**
   * @param {Phaser.Scene} scene
   * @param {MinionLooksHost} host The swarm that owns this manager.
   */
  constructor(scene, host) {
    this.scene = scene;
    this.host = host;
    /** @type {Map<number|string, Map<string, {type: object, state: ('loading'|'loaded'|'failed'), retryAt: number, retryTimer: ?object}>>} each player's custom looks by set version: the type, and whether its texture is on its way, in, or failed (and when it may be asked for again). A look is dropped from here when its texture is released. */
    this.records = new Map();
    /** @type {boolean} set by destroy(): a load or poof that finishes after the scene shut down finds it and touches nothing (the scene's own isShuttingDown is re-armed by a restart, this flag is not). */
    this.destroyed = false;
  }

  /**
   * Whether the manager holds a custom look of a player, in any state.
   *
   * @param {number|string} userId
   * @param {string} version The custom minion's set version.
   * @return {boolean}
   */
  holds(userId, version) {
    return this.records.get(userId)?.has(version) ?? false;
  }

  /**
   * Whether the manager holds any custom look of a player.
   *
   * @param {number|string} userId
   * @return {boolean}
   */
  holdsAny(userId) {
    return this.records.has(userId);
  }

  /**
   * Starts loading every custom minion of the player's pool that is neither loaded,
   * loading nor waiting out a retry after a failure. Idempotent: a repeat call for the same
   * pool asks the loader for nothing, and a pool with no custom minion asks for nothing.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {void}
   */
  loadPool(userId, entry) {
    const specs = customSpecsOf(entry.minionPool);
    if (specs.length === 0) {
      return;
    }
    const looks = this.records.get(userId) ?? new Map();
    this.records.set(userId, looks);
    for (const spec of specs) {
      const look = looks.get(spec.version);
      if (look?.state === 'loading' || look?.state === 'loaded') {
        continue;
      }
      if (look?.state === 'failed' && this.scene.time.now < look.retryAt) {
        continue;
      }
      look?.retryTimer?.remove();
      const record = { type: look?.type ?? customMinionType(userId, spec), state: 'loading', retryAt: 0, retryTimer: null };
      looks.set(spec.version, record);
      loadStackedType(this.scene, record.type).then(
        () => this._lookArrived(userId, record, 'loaded'),
        () => this._lookArrived(userId, record, 'failed'),
      );
    }
  }

  /**
   * What a spawn does about the player's custom minions, `spawnLookDecision` on this
   * player's pool and what of it is in.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @param {number} waitedMs How long the minion has been waiting for its look.
   * @return {('custom'|'wait'|'default')}
   */
  spawnDecision(userId, entry, waitedMs) {
    return spawnLookDecision({
      setSize: customSpecsOf(entry.minionPool).length,
      settled: this._isSetSettled(userId, entry),
      waitedMs,
      waitMs: TIMINGS.customMinionWaitMs,
    });
  }

  /**
   * The type a new look is drawn as: evenly from the pool, that is the default looks it
   * keeps and the custom minions that are loaded. A pool that keeps no default and has no
   * custom minion in yet (the server never sends a pool with nothing in it, but a custom
   * minion may still be loading) draws from every default, so a minion is never faceless.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {object}
   */
  pickSpawnType(userId, entry) {
    const loaded = this._loadedTypes(userId, entry);
    const defaults = defaultTypesOf(entry.minionPool);

    return pickMinionType(loaded, loaded.length === 0 && defaults.length === 0 ? MINION_TYPES : defaults, Math.random());
  }

  /**
   * Whether a minion showing `type` should be looked at again once more of the pool lands:
   * it shows a default although a custom minion of its player's pool is not in yet.
   *
   * @param {object} type
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {boolean}
   */
  isAwaitingCustom(type, userId, entry) {
    return !type.custom && this._hasUnloadedLook(userId, entry);
  }

  /**
   * Holds a just-created minion back, hidden with no look, until its player's custom
   * minions are in or `customMinionWaitMs` has passed, whichever comes first
   * (`_resolveWait`).
   *
   * @param {number|string} userId
   * @param {object} minion
   * @return {void}
   */
  holdForLook(userId, minion) {
    minion.waitTimer = this.scene.time.delayedCall(TIMINGS.customMinionWaitMs, () => {
      const current = this.scene.fighters.get(userId);
      if (this.destroyed || this.scene.isShuttingDown || !minion.sprite?.active || !current) {
        return;
      }
      this._resolveWait(userId, current, minion, true);
    });
  }

  /**
   * Reacts to a pool that just replaced the one the fighter held. Loads the custom minions
   * of the new pool when the player has minions out, draws the look of every minion on the
   * field again from the new pool (except one waiting for its first look, which its wait
   * settles), re-checks the waiting ones, and releases the looks nothing shows any more. A
   * minion whose draw is the look it already shows keeps it, with no poof. Each of them is
   * flagged `awaitingCustom` while the new pool has a custom look that is not in yet, and
   * draws once more when it lands.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters, its `minionPool` already the new one
   * @return {void}
   */
  poolChanged(userId, entry) {
    const list = this.host.minionsOf(userId);
    if (list.length > 0) {
      this.loadPool(userId, entry);
    }
    for (const minion of list) {
      if (!minion.waitingForLook) {
        this._requestSwap(minion, entry);
      }
    }
    this._resolveWaiting(userId);
    this.releaseUnused(userId);
  }

  /**
   * Starts a parked look swap when the minion is free: not summoning, fidgeting,
   * fighting, already swapping or waiting for its first look. Called at the end of the
   * summon, the fidget and the fight, and whenever a swap is requested. The swap poof
   * follows the minion, which keeps walking while it plays.
   *
   * @param {object} minion
   * @return {void}
   */
  flushParked(minion) {
    if (!minion.pendingLook || !minion.sprite?.active || this.destroyed || this.scene.isShuttingDown) {
      return;
    }
    if (minion.summoning || minion.fidgeting || minion.fighting || minion.swapping || minion.waitingForLook) {
      return;
    }
    const entry = this.scene.fighters.get(minion.userId);
    if (this.scene.reducedMotion || !entry) {
      this._applyParked(minion);
      return;
    }
    minion.swapping = true;
    this.host.playSwapPoof(minion, entry, () => {
      minion.swapping = false;
      if (this.destroyed || this.scene.isShuttingDown) {
        return;
      }
      this._applyParked(minion);
    });
  }

  /**
   * Takes custom looks out of the game once nothing needs them: no minion of the player
   * shows or has parked the look, and it either left the player's pool or the fighter left
   * the field. A look in the pool is kept whether or not minions are out, so the next
   * burst of subagents finds it loaded instead of hiding its first minions while it loads
   * again. A look still loading is left alone (its arrival runs this again); a failed look
   * stays (its retry window) while it is in the pool.
   *
   * @param {number|string} userId
   * @param {boolean} [fighterLeft] True when the fighter left the field: nothing is in its pool any more.
   * @return {void}
   */
  releaseUnused(userId, fighterLeft = false) {
    const looks = this.records.get(userId);
    if (!looks) {
      return;
    }
    const list = this.host.minionsOf(userId);
    const versions = new Set(customSpecsOf(this.scene.fighters.get(userId)?.minionPool).map(spec => spec.version));
    for (const [version, look] of looks) {
      if (look.state === 'loading') {
        continue;
      }
      const inPool = !fighterLeft && versions.has(version);
      if (look.state === 'failed') {
        if (!inPool) {
          look.retryTimer?.remove();
          looks.delete(version);
        }
        continue;
      }
      const shown = list.some(minion => minion.type?.key === look.type.key || minion.pendingLook?.key === look.type.key);
      if (shown || inPool) {
        continue;
      }
      releaseCustomFighter(this.scene, look.type);
      looks.delete(version);
    }
    if (looks.size === 0) {
      this.records.delete(userId);
    }
  }

  /**
   * Stops every retry timer and, on the way out, lets go of the textures nothing will ask
   * for again. Custom look textures and animations of a player whose swarm is out are KEPT:
   * they belong to the game, a restarted scene reuses them, and the fresh manager releases
   * them when that swarm is gone. A load or poof that finishes after this finds `destroyed`
   * set and touches nothing. Call it before the swarm forgets its minions.
   *
   * @return {void}
   */
  destroy() {
    this.destroyed = true;
    for (const [userId, looks] of this.records) {
      // A player with no minion out keeps nothing that a swarm will ask for again, and the fresh manager of a
      // restart never sees these looks (its records start empty, so it could not release them later):
      // let the textures go now. A player with minions out keeps them, their swarm is rebuilt on the same ones.
      const swarmOut = this.host.minionsOf(userId).length > 0;
      for (const look of looks.values()) {
        look.retryTimer?.remove();
        if (!swarmOut && look.state === 'loaded') {
          this._releaseOnTeardown(look.type);
        }
      }
    }
    this.records.clear();
  }

  /**
   * The custom types of a player's pool whose textures are in, in pool order.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {Array<object>}
   */
  _loadedTypes(userId, entry) {
    const looks = this.records.get(userId);
    if (!looks) {
      return [];
    }

    return customSpecsOf(entry.minionPool)
      .map(spec => looks.get(spec.version))
      .filter(look => look?.state === 'loaded')
      .map(look => look.type);
  }

  /**
   * Whether every custom minion of the pool has an answer: loaded, or failed (a failed one
   * stays out of the draw until its retry). True for a pool with no custom minion.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {boolean}
   */
  _isSetSettled(userId, entry) {
    const looks = this.records.get(userId);

    return customSpecsOf(entry.minionPool).every(spec => {
      const state = looks?.get(spec.version)?.state;

      return state === 'loaded' || state === 'failed';
    });
  }

  /**
   * Whether some custom minion of the pool is not in yet (loading, or failed and waiting
   * for its retry), so a minion drawn without it may still change to it.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @return {boolean}
   */
  _hasUnloadedLook(userId, entry) {
    const looks = this.records.get(userId);

    return customSpecsOf(entry.minionPool).some(spec => looks?.get(spec.version)?.state !== 'loaded');
  }

  /**
   * A look's load ended. After this manager was destroyed or the scene is shutting down (a
   * rotate-restart) it touches no state, and drops the texture the load just added unless
   * the scene's fresh manager already asked for the same look (`_dropOrphanLook`).
   * Otherwise it records the outcome and lets the waiting spawns, the minions waiting for a
   * custom look and the release check react: a look that came in draws again every minion
   * flagged `awaitingCustom` (whatever it shows, a default or a custom look still in the
   * pool) when that look is in the pool, and every minion showing a look that left the pool;
   * a look that landed after a newer pool dropped it draws no one. A failure changes no
   * minion and schedules the look's retry.
   *
   * @param {number|string} userId
   * @param {{type: object, state: string, retryAt: number, retryTimer: ?object}} record
   * @param {('loaded'|'failed')} state
   * @return {void}
   */
  _lookArrived(userId, record, state) {
    if (this.destroyed || this.scene.isShuttingDown) {
      if (state === 'loaded') {
        this._dropOrphanLook(userId, record);
      }
      return;
    }
    record.state = state;
    if (state === 'failed') {
      record.retryAt = this.scene.time.now + CUSTOM_RETRY_MS;
      this._scheduleRetry(userId, record);
    }
    this._resolveWaiting(userId);
    const entry = this.scene.fighters.get(userId);
    // Only a look that came in can change what a minion shows: a failure leaves the pool as loaded as it was,
    // and swapping on it again at every retry would poof the same default minions every CUSTOM_RETRY_MS.
    if (state === 'loaded' && entry && this._loadedTypes(userId, entry).length > 0) {
      for (const minion of this.host.minionsOf(userId)) {
        if (minion.waitingForLook) {
          continue;
        }
        if (this._wantsRedraw(minion, entry, record)) {
          this._requestSwap(minion, entry);
        }
      }
    }
    this.releaseUnused(userId);
  }

  /**
   * Whether a look that just landed calls for drawing the minion's look again: the minion
   * is flagged `awaitingCustom` and the look is still in the pool (a look that landed late,
   * after a newer pool dropped it, must not poof anyone), or the look the minion shows or
   * has parked left the pool.
   *
   * @param {object} minion
   * @param {object} entry the fighter entry from scene.fighters
   * @param {{type: object}} record the look that just landed
   * @return {boolean}
   */
  _wantsRedraw(minion, entry, record) {
    if (!isLookInPool(minion.pendingLook ?? minion.type, entry.minionPool)) {
      return true;
    }

    return minion.awaitingCustom && customSpecsOf(entry.minionPool).some(spec => spec.version === record.type.spec.version);
  }

  /**
   * Takes out of the game the texture a load added after its manager was destroyed. The
   * texture belongs to the game, so a restarted scene reuses it, which is why it is kept
   * when the scene's current manager (a fresh one after a restart) already holds a record
   * of the same look: it asked for it, and is about to register the animations on it.
   * Anything else would be a canvas nobody releases.
   *
   * @param {number|string} userId
   * @param {{type: object}} record
   * @return {void}
   */
  _dropOrphanLook(userId, record) {
    const successor = this.scene.minions?.looks;
    if (successor && successor !== this && successor.holds(userId, record.type.spec.version)) {
      return;
    }
    releaseCustomFighter(this.scene, record.type);
  }

  /**
   * Retries a failed look on its own after `CUSTOM_RETRY_MS`, instead of waiting for the
   * next change of the player's swarm to ask again. Only while the player has minions out;
   * with none, the next spawn asks (and `loadPool` still honours the retry window).
   *
   * @param {number|string} userId
   * @param {{retryTimer: ?object}} record
   * @return {void}
   */
  _scheduleRetry(userId, record) {
    record.retryTimer = this.scene.time.delayedCall(CUSTOM_RETRY_MS, () => {
      record.retryTimer = null;
      // The timer's clock and `scene.time.now` are not the same reading, so it can fire a few ms before
      // `retryAt`; the window it exists to wait out is over, so `loadPool` must not honour it again.
      record.retryAt = 0;
      const entry = this.scene.fighters.get(userId);
      if (this.destroyed || this.scene.isShuttingDown || !entry || this.host.minionsOf(userId).length === 0) {
        return;
      }
      this.loadPool(userId, entry);
    });
  }

  /**
   * Re-checks every minion of a player that is waiting for its look.
   *
   * @param {number|string} userId
   * @return {void}
   */
  _resolveWaiting(userId) {
    const entry = this.scene.fighters.get(userId);
    if (!entry) {
      return;
    }
    for (const minion of this.host.minionsOf(userId)) {
      if (minion.waitingForLook) {
        this._resolveWait(userId, entry, minion, false);
      }
    }
  }

  /**
   * Ends a minion's wait when `spawnLookDecision` says so: it takes a look from the pool
   * at random (a default one, flagged, while a custom minion is still not in, swapped once
   * it lands), and starts its summon.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @param {object} minion
   * @param {boolean} timedOut true when called by the wait's own timer
   * @return {void}
   */
  _resolveWait(userId, entry, minion, timedOut) {
    const waitMs = TIMINGS.customMinionWaitMs;
    const decision = this.spawnDecision(userId, entry, timedOut ? waitMs : this.scene.time.now - minion.waitStartedAt);
    if (decision === 'wait') {
      return;
    }
    minion.waitTimer?.remove();
    minion.waitTimer = null;
    minion.waitingForLook = false;
    const type = this.pickSpawnType(userId, entry);
    minion.awaitingCustom = this.isAwaitingCustom(type, userId, entry);
    this._applyLook(minion, type);
    this.host.beginSummon(userId, entry, minion);
  }

  /**
   * Puts a look on a minion: type key, type and ink height change together, then the
   * sprite takes the type's texture and frame and plays its idle loop.
   *
   * @param {object} minion
   * @param {object} type a custom or a default minion type
   * @return {void}
   */
  _applyLook(minion, type) {
    const { texture, frame } = minionSpriteFrame(type);
    minion.typeKey = type.key;
    minion.type = type;
    minion.charHeight = type.charHeight;
    minion.animState = 'idle';
    minion.sprite.setTexture(texture, frame);
    minion.sprite.play(`${type.key}-idle`);
  }

  /**
   * Asks a minion to draw its look again from its player's pool. The pick is parked on
   * `minion.pendingLook`, replacing an older one, and `flushParked` applies it unless the
   * minion is busy. Nothing happens when the pool offers nothing to change to yet (no
   * default kept, the custom minions still loading), and a pick that is the look the
   * minion already shows only clears what was parked: no poof. Whatever look it draws, the
   * minion is flagged `awaitingCustom` while the pool still has a custom look that is not
   * in, so that look gets its turn in the draw when it lands (`_lookArrived`).
   *
   * @param {object} minion
   * @param {object} entry the fighter entry from scene.fighters
   * @return {void}
   */
  _requestSwap(minion, entry) {
    const pick = pickSwapType({
      loaded: this._loadedTypes(minion.userId, entry),
      defaults: defaultTypesOf(entry.minionPool),
      shownKey: minion.type?.key ?? null,
      random: Math.random(),
    });
    if (pick === null) {
      return;
    }
    minion.awaitingCustom = this._hasUnloadedLook(minion.userId, entry);
    if (!pick.change) {
      minion.pendingLook = null;
      return;
    }
    minion.pendingLook = pick.type;
    this.flushParked(minion);
  }

  /**
   * Applies the look parked on a minion (the newest one asked for), then releases any
   * look nothing shows any more. A minion whose sprite is gone just drops it.
   *
   * @param {object} minion
   * @return {void}
   */
  _applyParked(minion) {
    const type = minion.pendingLook;
    minion.pendingLook = null;
    if (type && minion.sprite?.active) {
      this._applyLook(minion, type);
    }
    this.releaseUnused(minion.userId);
  }

  /**
   * Takes a look out of the game while the manager is being destroyed. On a page teardown the game's
   * texture and animation managers may already be gone, and there is nothing left to free then.
   *
   * @param {object} type The custom minion type to release.
   * @return {void}
   */
  _releaseOnTeardown(type) {
    try {
      releaseCustomFighter(this.scene, type);
    } catch {
      // the game is being torn down with the page: its textures go with it
    }
  }
}
