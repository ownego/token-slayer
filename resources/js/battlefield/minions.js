import Phaser from 'phaser';
import { MINION_TYPES, MINION_CLASH_EFFECTS, TIMINGS } from '@battlefield/config.js';
import { TextureKey } from '@battlefield/constants.js';
import { randomWanderPoint } from './boss/bat-wander.js';
import { homeSlotOffset, homeSlotAngle, isInFrontOfFighter } from './minion-layout.js';
import { sampleTrail, pushTrailSample } from './minion-trail.js';
import { computeZones, zoneFanOffset } from './minion-grouping.js';

/** Ink height (px) of a MINION_TYPES idle frame within its 100x100 source frame — measured directly off Demon_A/Blood Monster_A's Idle strip, mirrors fighter/index.js's own SPRITE_CHAR_HEIGHT constant for the fighter atlas. */
const MINION_CHAR_HEIGHT = 20;
/** A minion's visual height as a fraction of its fighter's own current effective size (baseSize * the container's own live scaleX — see _currentSize) — recomputed every frame since minions are independent world-space sprites now (not container children), so nothing else carries crowd/damage growth for them. */
const MINION_SIZE_RATIO = 0.5;
/** A minion's own avatar badge (the owning fighter's avatar image, shrunk down) as a fraction of the minion's current display size — small enough to read as a tag, not compete with the minion sprite itself. */
const MINION_BADGE_SIZE_RATIO = 0.55;
/** How far above the minion's own top the badge sits, as a fraction of the minion's current visual height. */
const MINION_BADGE_OFFSET_RATIO = 0.65;
/** Caps how many minions render per fighter regardless of the real dispatched count, so a burst of parallel subagents never clutters the battlefield. Raised from 6 to 10 — live 2026-09-25 a fighter with genuinely 7+ concurrent subagents (a busy `Agent` SDK-based harness) was visibly clamped below its real count. */
const MINION_MAX_VISIBLE = 10;
/** Forced depth for the whole spawn ceremony (ground circle + rise-in) — always above the fighter's own depth (2) so the summon reads clearly, never hidden behind the character. Deliberately not the bare integer 3: several transient attack-effect graphics (arrow trail, blast beam, ghost fx, per-attack sprite) also render at depth 3, and same-depth tie-breaking is insertion-order-dependent — a fractional depth keeps a spawning minion unambiguously above those too, not just above the fighter. Once a minion has finished spawning it stops being pinned here and switches to the dynamic front/back sort below. */
const MINION_DEPTH_FRONT = 3.2;
/** Depth for a settled minion currently positioned "behind" its fighter (see isInFrontOfFighter) — below the fighter's own depth (2), same as the other "tucked behind the character" elements already in this scene (fighter/index.js's charge glow/flash/core and its flair ring's own back arc). */
const MINION_DEPTH_BACK = 1;

/** Per-link delay (ms): minion i (0-indexed) samples the leader trail this many * (i+1) ms in the past while its fighter is genuinely moving, so the whole chain reads as one train retracing the real path. */
const FOLLOW_DELAY_MS = 220;
/** Leader trail history is trimmed to this age; must comfortably cover the oldest minion's sample delay. */
const TRAIL_MAX_AGE_MS = FOLLOW_DELAY_MS * (MINION_MAX_VISIBLE + 2);
/** A fighter sampled moving faster than this (px/s) switches its minions from independent idle wander to trail-follow. Idle fighters sit at exactly 0 in world space, so any small positive threshold cleanly separates "genuinely moving" from floating-point noise. */
const MOVE_SPEED_THRESHOLD_PX_PER_S = 4;

/** Distance from the foot anchor to each minion's own idle "home slot", as a fraction of the fighter's current effective size — evenly divided among the current minion count (homeSlotOffset) so they spread out around the fighter instead of stacking. */
const IDLE_HOME_RADIUS_RATIO = 0.9;
/** How far a minion wanders from its own home slot while idling, as a fraction of the fighter's current effective size. */
const IDLE_WANDER_RADIUS_RATIO = 0.3;
/** Idle wander is deliberately slow ("chạy chậm") — long pauses, slow moves — unlike the snappier trail-follow during an actual move. */
const IDLE_PAUSE_MIN_MS = 1200;
const IDLE_PAUSE_MAX_MS = 2800;
const IDLE_MOVE_MIN_MS = 1000;
const IDLE_MOVE_MAX_MS = 1800;

/** A minion sampled below this speed is considered settled — eligible for Idle animation and for the idle fidget, instead of Walk. */
const WALK_SPEED_THRESHOLD_PX_PER_S = 8;

/** Cosmetic idle-fidget cadence — "khè khè / múa múa" flourish while a minion is settled near the feet. Purely decorative, reuses Attack01/Attack02 for the animation only; never touches damage. */
const FIDGET_MIN_MS = 3000;
const FIDGET_MAX_MS = 8000;

/** Two minions belonging to DIFFERENT fighters within this world-space distance (px) trigger a cosmetic clash. Purely decorative — never touches damage/HP, same as the idle fidget. */
const FIGHT_PROXIMITY_PX = 34;
/** Cooldown after a clash before either participant is eligible to fight again, so two minions that happen to be lingering close together don't retrigger every frame. */
const FIGHT_COOLDOWN_MS = 5000;
/** Fire-toned tints for the clash particle burst — the same default palette Charge.chargeParticleColors falls back to for a fighter with no chargeColors of its own, duplicated here (rather than importing Charge) to keep minions.js free of a dependency on the charge manager for one small constant array. */
const CLASH_PARTICLE_TINTS = [0x991100, 0xcc3300, 0xdd6600, 0xee9900, 0xffbb00];
/** How many particles the clash burst emits — small since it plays at minion scale, not a full attack-sized burst (compare slashBurst's 9-20 in attacks/fx.js). */
const CLASH_PARTICLE_COUNT = 6;
/** Milliseconds before a clash particle emitter is force-destroyed as a backstop, in case its own lifespan-based auto-cleanup is ever skipped. */
const CLASH_PARTICLE_CLEANUP_MS = 500;
/** How many staggered explosion bursts one clash plays, instead of a single flash — "vài vụ nổ quanh khu vực đánh nhau" rather than one quick pop. */
const CLASH_VFX_BURST_COUNT = 3;
/** Spacing (ms) between each staggered burst, roughly spread across the clash's own attack-animation length so the sequence reads as one sustained melee, not a separate effect after it. */
const CLASH_VFX_STAGGER_MS = 180;
/** Each staggered burst's random position jitter, as a fraction of the clashing minions' own visual height, so the bursts scatter around the midpoint rather than stacking on the exact same point. */
const CLASH_VFX_JITTER_RATIO = 0.6;

/** Fighter-to-fighter distance (as a multiple of their average current size) within which they "gather toward each other" — size-relative rather than a fixed px value so it self-scales with fighterDisplayConfig's crowd-size shrink and with damage growth, matching every other distance minions.js already computes. Narrowed from 7 — caught live 2026-09-24 as "hơi rộng" (a bit too wide): fighters that read as clearly separate on screen were still triggering gathering. */
const NEAR_FIGHTER_RATIO = 5;
/** Angular width (radians) each zone's minions fan out across around the zone's own direction, so several minions sharing one zone spread out instead of stacking on the same point. */
const ZONE_ARC_WIDTH_RAD = Math.PI / 2;
/** How far toward a zone's nearest neighbor a minion's own radius reaches, as a fraction of the distance to it — 0.5 means roughly the midpoint, so two fighters' own minions actually meet (and can reach FIGHT_PROXIMITY_PX of each other) instead of each swarm just leaning toward the other while still anchored at its own fixed idle radius. Verified live 2026-09-23: at the plain IDLE_HOME_RADIUS_RATIO radius, two fighters near enough to gather toward each other were often still too far apart for any minion to actually reach fight range, so a whole nearby swarm could visibly "lean in" without a single clash ever triggering. */
const ZONE_RADIUS_MIDPOINT_FRACTION = 0.5;
/** Caps a zone's radius at this multiple of the fighter's own plain idle radius, so a neighbor near the outer edge of NEAR_FIGHTER_RATIO doesn't pull minions absurdly far from their own fighter. Must be >= NEAR_FIGHTER_RATIO / (2 * IDLE_HOME_RADIUS_RATIO) (~2.8 at the current values, comfortably under this) or the cap binds before the true midpoint for a neighbor in the outer slice of the near-range, reproducing the very "swarm leans in but never actually reaches fight range" bug this whole radius scaling exists to fix — caught by battlefield-reviewer, which had originally shipped at 3, back when NEAR_FIGHTER_RATIO was 7 (minimum ~3.9 then). Still safe after narrowing NEAR_FIGHTER_RATIO to 5 — re-check this inequality if either constant changes again. */
const ZONE_RADIUS_MAX_RATIO = 4;
/** The walking pace (px/s) update() caps EVERY minion's per-frame position step at, whatever is driving its target — a trail sample, a zone target, or the plain home slot. While trail-following, the fighter's own currently-measured speed is used instead when it's faster (so a lagging minion still closes a wide gap at a believable pace); this is the floor for that, and the only value used at all in idle mode, where the fighter's own speed is ~0 by definition. Picked well under the fighter's own real move speed (300px/s, fighter/index.js's SPEED_PX_PER_SEC) so it never reads as a burst even at this floor. */
const MINION_WALK_SPEED_PX_PER_S = 150;

/** Pulsing ring color for a minion's "using a tool right now" indicator — the same cyan Charge.createChargingRing uses for a fighter's own charging ring ("vòng và nhấp nháy như user"), duplicated here (rather than importing Charge) for the same reason CLASH_PARTICLE_TINTS is duplicated above. */
const TOOL_RING_COLOR = 0x22d3ee;
/** How far the ring sits outside the minion's badge edge, as a fraction of the badge's own current display size — mirrors Charge.createChargingRing's own `avR + max(4, displaySize*0.08)` margin shape, just scaled down since a minion's badge is far smaller in absolute px than a fighter's avatar. */
const TOOL_RING_MARGIN_RATIO = 0.15;
/** Duration (ms) of the ring's fade-out when a subagent's tool call ends, instead of an instant pop — same shape as _dismissSummonCircle's own fade. */
const TOOL_RING_FADE_OUT_MS = 200;

/** How long before a spawning minion's rise-in reveal (see _spawnOne) the summon circle appears — gives the ground effect a moment to form before the character rises through it, same lead time fighter/index.js's own join ceremony uses. */
const SUMMON_CIRCLE_LEAD_MS = 150;
/** Scale of the reused necromancer summon-circle effect for a minion's much smaller rise-in, vs. its 2.6 default for a full fighter join. */
const SUMMON_CIRCLE_SCALE = 1.3;
const SUMMON_RISE_MS = 260;
/** How far below its final spot a minion starts its rise, as a fraction of its own current visual height — mirrors fighter/index.js's own 0.6 ratio for the same fallback rise-in. */
const SUMMON_RISE_OFFSET_RATIO = 0.6;

/**
 * Manages the small ambient minion swarm that visualizes a fighter's
 * currently-dispatched-but-not-yet-stopped subagents. Purely cosmetic —
 * never touches HP/damage. Each minion is an independent world-space sprite
 * (not a child of the fighter's container — a container child moves in
 * perfect lockstep with its parent, which cannot produce a trailing-behind
 * effect), repositioned every frame in update().
 *
 * Two movement modes, switched per-fighter based on its own sampled speed:
 * - **Idle** (fighter stationary): each minion wanders independently within
 *   its own "home slot" — evenly spread around the full circle
 *   (homeSlotOffset) when no other fighter is near, or gathered into one or
 *   more zones biased toward nearby fighters otherwise (see Near-fighter
 *   gathering zones below) — slow, decorrelated, never stacking on another
 *   minion.
 * - **Moving** (fighter mid click-to-move): minions switch to trail-follow —
 *   minion i replays the fighter's own recently-recorded foot path
 *   (i+1)*FOLLOW_DELAY_MS in the past (sampleTrail/minion-trail.js), so the
 *   whole chain reads as one train retracing the real route, bends included.
 *
 * **Near-fighter gathering zones.** Every frame, an idle fighter's own
 * "home slot" direction is computed by minion-grouping.js's computeZones
 * against every OTHER currently-active fighter: no one near reduces to the
 * plain homeSlotAngle fallback above (unchanged from before this existed);
 * one or more nearby fighters instead produces one zone per cluster of
 * nearby-neighbor directions (two fighters -> one zone facing each other;
 * a triangle's usual angles merge into one zone per fighter, oriented
 * toward the group; fighters standing in a near-straight line put the
 * middle one between two opposing zones — see computeZones's own docblock
 * for the single clustering rule that produces all of these). Each minion
 * is assigned to one of its fighter's current zones (see _assignZones),
 * fans out within it (zoneFanOffset) so several minions in the same zone
 * don't stack. The target angle/radius itself (_setHomeTarget) updates
 * immediately, with no smoothing at that level — update()'s own walk-speed
 * cap on the minion's actual rendered position is what turns any resulting
 * jump (zone-count change, a zone's direction/distance drifting as someone
 * walks, a neighbor leaving) into a walk instead of a snap.
 */
export class Minions {
  /**
   * @param {Phaser.Scene} scene
   */
  constructor(scene) {
    this.scene = scene;
    /** @type {Map<number|string, Array<object>>} */
    this.byUser = new Map();
    /** @type {Map<number|string, {history: Array<{x: number, y: number, t: number}>, lastFoot: {x: number, y: number}, lastUpdateAt: number, moving: boolean}>} */
    this.leaders = new Map();
    /** @type {Map<number|string, number>} the last-applied FighterAgentCountChanged seq per user, so a broadcast that arrives after a fresher one it already applied is discarded instead of regressing the swarm — see FighterAgentCountChanged's own docblock. */
    this.lastAppliedSeq = new Map();
  }

  /**
   * Adjusts a fighter's minion swarm to match its current agent count,
   * spawning or despawning sprites as needed. A no-op for a fighter not
   * currently on the battlefield (its next FighterJoined boots with zero
   * minions regardless — this count is cosmetic-only and never snapshotted),
   * or for a broadcast whose seq is not newer than the last one already
   * applied for this user (out-of-order delivery under concurrent dispatches
   * racing on the same user — the server has no way to guarantee wire order
   * matches the order its own atomic writes actually happened in).
   *
   * @param {{user_id: number|string, count: number, seq?: number}} payload
   * @return {void}
   */
  handleAgentCountChanged(payload) {
    if (!payload || payload.user_id == null) {
      return;
    }
    const userId = payload.user_id;
    if (payload.seq != null) {
      const lastSeq = this.lastAppliedSeq.get(userId);
      if (lastSeq != null && payload.seq <= lastSeq) {
        return;
      }
      this.lastAppliedSeq.set(userId, payload.seq);
    }
    const entry = this.scene.fighters.get(userId);
    if (!entry?.sprite?.active) {
      return;
    }
    this._ensureAnims();

    const list = this.byUser.get(userId) ?? [];
    const target = Phaser.Math.Clamp(payload.count, 0, MINION_MAX_VISIBLE);
    while (list.length < target) {
      list.push(this._spawnOne(userId, entry, list.length, target));
    }
    while (list.length > target) {
      this._destroyOne(list.pop());
    }
    this.byUser.set(userId, list);

    if (list.length === 0) {
      this._removeLeader(userId);
    }
  }

  /**
   * Lights up (or turns off) the pulsing ring on the ONE minion sprite
   * currently assigned to this specific agent_id, driven by
   * FighterAgentToolUsed. Minions are otherwise anonymous — nothing else
   * tracks which physical sprite corresponds to which real subagent — so
   * an agent_id is assigned to the first still-unassigned minion the first
   * time it goes busy (`minion.assignedAgentId`), and freed the moment that
   * same agent_id goes idle again, ready for a different agent_id to claim
   * it later. Deliberately NOT a full identity-keyed rewrite of spawn/
   * despawn (still purely count-based, see handleAgentCountChanged) — this
   * lighter assignment layer sits on top of it and is accurate for the
   * common case (dispatched count <= MINION_MAX_VISIBLE); beyond the cap,
   * an agent_id with nothing free to claim just never gets a ring, the same
   * existing limitation the count itself already has.
   *
   * @param {{user_id: number|string, agent_id: string, busy: boolean}} payload
   * @return {void}
   */
  handleAgentToolUsed(payload) {
    if (!payload || payload.user_id == null || !payload.agent_id) {
      return;
    }
    const list = this.byUser.get(payload.user_id);
    if (!list || list.length === 0) {
      return;
    }

    if (payload.busy) {
      if (list.some(m => m.assignedAgentId === payload.agent_id)) {
        return;
      }
      const free = list.find(m => m.assignedAgentId == null && m.sprite?.active);
      if (!free) {
        return;
      }
      free.assignedAgentId = payload.agent_id;
      this._showToolRing(free);
    } else {
      const owner = list.find(m => m.assignedAgentId === payload.agent_id);
      if (!owner) {
        return;
      }
      owner.assignedAgentId = null;
      this._dismissToolRing(owner);
    }
  }

  /**
   * Sets a minion's rendered home-slot angle/radius straight to their
   * current target — no smoothing at this level. One assignment for every
   * reason the target moves: a fighter's minion count changing
   * (homeSlotAngle's denominator shifts), or a gather-zone's direction/
   * distance drifting as it or a nearby fighter walks. Any resulting jump
   * in the raw target is absorbed downstream by update()'s own walk-speed
   * cap on the minion's actual rendered position (see its own comment) —
   * a SEPARATE exponential-lerp smoothing pass here used to also run, but
   * it shared the trail-follow bootstrap bug's exact flaw (moves fastest
   * right when the gap is biggest, reading as a fast "vút" dash rather
   * than a walk) for the zone-gather and neighbor-leaves cases specifically
   * — caught live 2026-09-24 ("khi chạy đến chỗ nào đó thì chạy tốc độ
   * thường... chạy đến đánh nhau/người khác bỏ đi cũng vậy"). One walk-
   * speed cap, applied uniformly to wherever a minion is heading (trail
   * sample or idle target), replaces both mechanisms.
   *
   * @param {object} minion
   * @param {number} targetAngle
   * @param {number} targetRadius
   * @return {void}
   */
  _setHomeTarget(minion, targetAngle, targetRadius) {
    minion.homeAngle = targetAngle;
    minion.homeRadius = targetRadius;
  }

  /**
   * Resyncs one settled minion's homeAngle/homeRadius/idleOffset from its
   * OWN currently-rendered position (relative to the foot) rather than
   * whatever stale value they were last stepped to — called exactly once
   * per minion, the frame THAT minion individually finishes trail-following
   * (see update()'s per-minion `usesTrail` — not a single shared fighter-
   * level flag, since each minion samples the trail at its own delay and so
   * finishes catching up at its own time, not all at once the instant the
   * fighter itself stops). Without this resync the very first idle frame
   * would jump from the minion's real trail-follow position straight to
   * wherever its stale pre-catch-up angle/radius happens to point — a
   * visible snap right as it arrives.
   *
   * idleOffset (the minion's own independent slow wander drift) gets the
   * same treatment for the same reason: _idlePoint adds it ON TOP of
   * home(angle, radius), and it too sits frozen/stale from before trail-
   * following started — resyncing angle/radius alone still left a smaller
   * but visible extra pop from this leftover offset, caught live by the
   * user. Killing its wander tween first (an in-flight one would otherwise
   * keep overwriting the reset from its own interpolation state next frame)
   * also orphans the recursive _scheduleIdleWander chain (its onComplete
   * never fires for a tween that's killed rather than completed), so this
   * restarts it explicitly rather than leaving the minion's idle wander
   * permanently stopped after its first resync.
   *
   * @param {number|string} userId
   * @param {object} minion
   * @param {{x: number, y: number}} foot
   * @return {void}
   */
  _resyncMinionHome(userId, minion, foot) {
    const relX = minion.sprite.x - foot.x;
    const relY = minion.sprite.y - foot.y;
    minion.homeAngle = Math.atan2(relY, relX);
    minion.homeRadius = Math.hypot(relX, relY);

    minion.idleTimer?.remove();
    this.scene.tweens.killTweensOf(minion.idleOffset);
    minion.idleOffset.x = 0;
    minion.idleOffset.y = 0;
    this._scheduleIdleWander(userId, minion);
  }

  /**
   * Repositions every active minion (idle home-slot wander or trail-follow,
   * whichever its fighter's own current speed calls for), advances each
   * fighter's leader trail, and resolves any cross-fighter minion clashes.
   * Called once per frame from scene.js's update().
   *
   * @param {number} time the current time (scene.time.now), ms
   * @return {void}
   */
  update(time) {
    /** @type {Array<{userId: number|string, minion: object}>} */
    const allActive = [];

    // One shared snapshot of every currently-active fighter's own foot
    // position/size, built once per frame rather than per-fighter, since
    // computeZones needs every OTHER fighter's position to find each
    // fighter's own near neighbors.
    const fighterPoints = [];
    for (const [uid, fEntry] of this.scene.fighters) {
      if (fEntry?.sprite?.active) {
        const fFoot = this._footPosition(fEntry);
        fighterPoints.push({ id: uid, x: fFoot.x, y: fFoot.y, size: this._currentSize(fEntry) });
      }
    }

    for (const [userId, list] of this.byUser) {
      if (list.length === 0) {
        continue;
      }
      const entry = this.scene.fighters.get(userId);
      const leader = this.leaders.get(userId);
      if (!entry?.sprite?.active || !leader) {
        continue;
      }

      const foot = this._footPosition(entry);
      const dtSeconds = Math.max(1, time - leader.lastUpdateAt) / 1000;
      const fighterSpeed = Math.hypot(foot.x - leader.lastFoot.x, foot.y - leader.lastFoot.y) / dtSeconds;
      leader.moving = fighterSpeed > MOVE_SPEED_THRESHOLD_PX_PER_S;
      leader.lastFoot = foot;
      leader.lastUpdateAt = time;

      if (leader.moving) {
        leader.history = pushTrailSample(leader.history, { x: foot.x, y: foot.y, t: time }, TRAIL_MAX_AGE_MS);
      }
      // The trail's own last recorded timestamp — a fighter that just
      // stopped leaves this frozen at its stop moment, while `time` keeps
      // advancing every frame after. Minion i's own sample time lagging
      // behind it means that minion is still genuinely catching up along
      // the real recorded path (still walking at the same apparent pace
      // sampleTrail already produces), even though the fighter itself has
      // already stopped and leader.moving is now false.
      const trailEndsAt = leader.history.length > 0 ? leader.history[leader.history.length - 1].t : -Infinity;

      const scale = this._minionScale(entry);
      const plainHomeRadius = this._currentSize(entry) * IDLE_HOME_RADIUS_RATIO;

      // Only computed/needed for the idle branch below — a moving fighter's
      // minions trail-follow instead, ignoring zones entirely until it
      // settles again.
      let zoneByKey = null;
      let membersByKey = null;
      if (!leader.moving) {
        const me = { id: userId, x: foot.x, y: foot.y, size: this._currentSize(entry) };
        // Only a neighbor that ALSO currently has minions of its own is
        // worth gathering toward — nothing to meet or clash with at an
        // empty-handed fighter's spot, so it should never pull this
        // fighter's own swarm off its normal all-around spread. Caught
        // live: a nearby fighter with zero agents was still lopsiding the
        // gathering toward it.
        const others = fighterPoints.filter(p => p.id !== userId && this.byUser.get(p.id)?.length > 0);
        const zones = computeZones(me, others, { nearRatio: NEAR_FIGHTER_RATIO });
        this._assignZones(list, zones);
        zoneByKey = new Map(zones.map(z => [this._zoneKey(z), z]));
        membersByKey = new Map();
        for (const minion of list) {
          if (minion.zoneKey == null) {
            continue;
          }
          if (!membersByKey.has(minion.zoneKey)) {
            membersByKey.set(minion.zoneKey, []);
          }
          membersByKey.get(minion.zoneKey).push(minion);
        }
      }

      list.forEach((minion, i) => {
        if (!minion.sprite?.active) {
          return;
        }
        if (leader.moving && minion.summonCircle) {
          this._dismissSummonCircle(minion);
        }
        if (!minion.summoning) {
          // Per-minion, not a single shared fighter-level flag: minion i
          // samples the trail (i+1)*FOLLOW_DELAY_MS in the past, so each one
          // finishes catching up to the fighter's own stop point at its own
          // time, not all simultaneously the instant the fighter itself
          // stops. Cutting every minion over to idle mode together the
          // moment leader.moving drops read as a lagging minion suddenly
          // dashing to its new idle/zone target instead of continuing to
          // walk in at the same steady pace — caught live by the user.
          const sampleTime = time - (i + 1) * FOLLOW_DELAY_MS;
          const usesTrail = leader.moving || sampleTime < trailEndsAt;

          if (minion.wasTrailFollowing && !usesTrail) {
            this._resyncMinionHome(userId, minion, foot);
          }
          minion.wasTrailFollowing = usesTrail;

          if (!usesTrail) {
            const zone = minion.zoneKey != null ? zoneByKey.get(minion.zoneKey) : null;
            const targetAngle = zone
              ? zone.angle + zoneFanOffset(membersByKey.get(minion.zoneKey).indexOf(minion), membersByKey.get(minion.zoneKey).length, ZONE_ARC_WIDTH_RAD)
              : homeSlotAngle(i, list.length);
            // A zone reaches toward the midpoint with its nearest neighbor
            // (clamped so a neighbor right at the edge of "near" doesn't
            // pull minions absurdly far) — the plain fallback keeps the
            // fighter's own fixed idle radius, unchanged from before zones
            // existed.
            const targetRadius = zone
              ? Phaser.Math.Clamp(zone.distance * ZONE_RADIUS_MIDPOINT_FRACTION, plainHomeRadius, plainHomeRadius * ZONE_RADIUS_MAX_RATIO)
              : plainHomeRadius;
            this._setHomeTarget(minion, targetAngle, targetRadius);
          }
          const point = usesTrail
            ? sampleTrail(leader.history, sampleTime)
            : this._idlePoint(foot, this._homeOffset(minion), minion);
          if (point) {
            // Never let a single frame's step toward `point` outrun a
            // normal walk, whether it's a trail sample or an idle target
            // (zone, or the plain home slot once a neighbor leaves) —
            // cap it at the fighter's own currently-measured speed when
            // that's faster (only relevant while trail-following; a
            // fighter this minion is idling around is ~0 speed by
            // definition), falling back to MINION_WALK_SPEED_PX_PER_S
            // otherwise. `point` itself can jump arbitrarily between
            // frames now that neither the trail's own start-of-move
            // clamping nor a target angle/radius reassignment is smoothed
            // upstream — early in a fresh move `point` is sampleTrail's
            // clamped-to-history[0] value (the same fixed spot every
            // minion would otherwise land on); a zone appearing/dissolving
            // can swing the idle target just as far in one frame. Without
            // this cap either reads as a fast "vút" dash rather than a
            // walk. In ordinary steady-state following, `point` only
            // advances roughly fighterSpeed*dtSeconds per frame, so the
            // cap rarely actually engages there and the real recorded
            // path's shape (corners included) is preserved. Caught live
            // 2026-09-24, twice: "lúc gom lại nhanh thế... chạy như bình
            // thường thôi đừng vút 1 cái như thế", then again for the
            // idle-target case specifically once trail-following was
            // fixed but zone-gather/neighbor-leaves still used a separate,
            // equally snap-prone exponential lerp ("chạy đến đánh nhau
            // thì cũng chạy tốc độ thường... người khác bỏ đi thì cũng nên
            // chạy tốc độ thường về chỗ cần về, đừng vụt 1 phát").
            const gapX = point.x - minion.sprite.x;
            const gapY = point.y - minion.sprite.y;
            const gap = Math.hypot(gapX, gapY);
            const maxStep = Math.max(fighterSpeed, MINION_WALK_SPEED_PX_PER_S) * dtSeconds;
            let targetX = point.x;
            let targetY = point.y;
            if (gap > maxStep) {
              targetX = minion.sprite.x + (gapX / gap) * maxStep;
              targetY = minion.sprite.y + (gapY / gap) * maxStep;
            }
            const dx = targetX - minion.sprite.x;
            const dy = targetY - minion.sprite.y;
            minion.sprite.setPosition(targetX, targetY);
            const minionDepth = isInFrontOfFighter(targetY, foot.y) ? MINION_DEPTH_FRONT : MINION_DEPTH_BACK;
            minion.sprite.setDepth(minionDepth);
            if (Math.abs(dx) > 0.5) {
              minion.sprite.setFlipX(dx < 0);
            }
            this._updateMinionAnim(minion, Math.hypot(dx, dy) / dtSeconds);
            this._positionBadge(minion, { x: targetX, y: targetY }, scale, minionDepth, entry);
            this._positionToolRing(minion, { x: targetX, y: targetY }, scale, minionDepth);
          }
          minion.sprite.setScale(scale);
        }
        allActive.push({ userId, minion });
      });
    }

    this._updateFights(time, allActive);
  }

  /**
   * Keeps each minion's zone assignment stable as long as the zone it was
   * assigned to (identified by its own sorted neighbor-id combination, see
   * _zoneKey) still exists this frame; only (re)assigns a minion — to one of
   * the current zones at random — when it has none yet or its old zone's
   * neighbor combination is no longer among them (a neighbor left near
   * range, or the cluster split/merged into a different combination).
   * Mutates each minion's own `zoneKey` in place; `null` for every minion
   * when `zones` is empty; called once per fighter per frame, not per minion.
   *
   * @param {Array<object>} list
   * @param {Array<{angle: number, neighborIds: Array<number|string>}>} zones
   * @return {void}
   */
  _assignZones(list, zones) {
    if (zones.length === 0) {
      for (const minion of list) {
        minion.zoneKey = null;
      }
      return;
    }
    const keys = zones.map(z => this._zoneKey(z));
    const keySet = new Set(keys);
    for (const minion of list) {
      if (minion.zoneKey == null || !keySet.has(minion.zoneKey)) {
        minion.zoneKey = keys[Phaser.Math.Between(0, keys.length - 1)];
      }
    }
  }

  /**
   * A zone's stable identity for the current frame: its neighbor ids,
   * sorted and joined, so the same neighbor combination always produces the
   * same key regardless of the array order computeZones happened to return
   * it in.
   *
   * @param {{neighborIds: Array<number|string>}} zone
   * @return {string}
   */
  _zoneKey(zone) {
    return zone.neighborIds.map(String).sort().join(',');
  }

  /**
   * A minion's target idle-mode position: its own evenly-spaced home slot
   * around the foot, plus its own independent slow wander offset within it.
   *
   * @param {{x: number, y: number}} foot the fighter's current foot position
   * @param {{x: number, y: number}} home this minion's home-slot offset from the foot
   * @param {object} minion
   * @return {{x: number, y: number}}
   */
  _idlePoint(foot, home, minion) {
    return {
      x: foot.x + home.x + minion.idleOffset.x,
      y: foot.y + home.y + minion.idleOffset.y,
    };
  }

  /**
   * A minion's current home-slot offset from the foot: its own
   * homeAngle/homeRadius (set straight to the current target every frame
   * by _setHomeTarget — never read live off list length/index or zone
   * state directly here). Both are plain per-minion numbers, not derived
   * from the fighter's current size directly, so a zone's distance-scaled
   * reach and the plain fallback's fighter-size-scaled reach share the
   * exact same downstream walk-speed cap on the minion's rendered
   * position (see update()'s own comment) rather than each needing their
   * own smoothing.
   *
   * @param {object} minion
   * @return {{x: number, y: number}}
   */
  _homeOffset(minion) {
    return { x: minion.homeRadius * Math.cos(minion.homeAngle), y: minion.homeRadius * Math.sin(minion.homeAngle) };
  }

  /**
   * Clears a fighter's minion bookkeeping and leader trail state when it
   * leaves the battlefield. Unlike a container child, an independent
   * world-space sprite is never destroyed for free — this must destroy each
   * minion sprite itself.
   *
   * @param {number|string} userId
   * @return {void}
   */
  despawnAll(userId) {
    const list = this.byUser.get(userId);
    if (list) {
      for (const minion of list) {
        this._teardownMinion(minion);
        minion.sprite?.destroy();
        minion.badge?.destroy();
      }
      this.byUser.delete(userId);
    }
    this._removeLeader(userId);
  }

  /**
   * Spawns one minion of a randomly-chosen type at its own home slot,
   * playing a scaled-down version of the fighter-join summon ceremony
   * (ground circle + golden-glow rise-in) — without the Necromancer sprite
   * itself performing it, since a whole team of them would be visual noise.
   *
   * @param {number|string} userId
   * @param {object} entry the fighter entry from scene.fighters
   * @param {number} index this minion's 0-indexed position in its fighter's about-to-be list
   * @param {number} count the fighter's final minion count after this spawn
   * @return {object}
   */
  _spawnOne(userId, entry, index, count) {
    this._ensureLeader(userId);
    const type = MINION_TYPES[Phaser.Math.Between(0, MINION_TYPES.length - 1)];
    const foot = this._footPosition(entry);
    const home = homeSlotOffset(index, count, this._currentSize(entry) * IDLE_HOME_RADIUS_RATIO);
    const spawnX = foot.x + home.x;
    const spawnY = foot.y + home.y;
    const finalScale = this._minionScale(entry);

    const summonCircle = this.scene.necromancer?.spawnSummonCircle(spawnX, spawnY, SUMMON_CIRCLE_SCALE) ?? null;

    const sprite = this.scene.add
      .sprite(spawnX, spawnY, `${type.key}-idle`)
      .setDepth(MINION_DEPTH_FRONT)
      .setAlpha(0)
      .play(`${type.key}-idle`);

    // Reuses the owning fighter's OWN already-loaded avatar texture (real
    // photo once loaded, or its generated fallback in the meantime) rather
    // than fetching anything of its own — "để còn biết subagent của ai" when
    // several fighters' swarms gather into the same zone. Read once at spawn
    // time, not kept in sync with a later async avatar-load completing (a
    // minion spawning in that brief window keeps the fallback for its own
    // lifetime) — an acceptable simplification for a small cosmetic tag.
    //
    // setDisplaySize is required here, not left to the first _positionBadge
    // call: the reveal below (minion.badge?.setAlpha(1)) fires BEFORE the
    // rise-in tween even starts, while minion.summoning is still true —
    // _positionBadge only ever runs once summoning is false, so without an
    // explicit small size up front the badge would sit visible at its
    // native texture size (a full avatar canvas, far bigger than a minion)
    // for the whole ~SUMMON_RISE_MS window, then snap down to correct size
    // the instant summoning flips false — caught live as "bụp 1 phát từ to
    // hóa về bình thường".
    const badgePx = finalScale * MINION_CHAR_HEIGHT * MINION_BADGE_SIZE_RATIO;
    const badge = this.scene.add
      .image(spawnX, spawnY, entry.head?.texture?.key ?? sprite.texture.key)
      .setDisplaySize(badgePx, badgePx)
      .setAlpha(0)
      .setDepth(MINION_DEPTH_FRONT);

    const minion = {
      sprite,
      badge,
      typeKey: type.key,
      animState: 'idle',
      fidgeting: false,
      fighting: false,
      summoning: true,
      fightCooldownUntil: 0,
      fidgetTimer: null,
      idleTimer: null,
      summonTimer: null,
      idleOffset: { x: 0, y: 0 },
      homeAngle: homeSlotAngle(index, count),
      homeRadius: this._currentSize(entry) * IDLE_HOME_RADIUS_RATIO,
      zoneKey: null,
      wasTrailFollowing: false,
      summonCircle,
      assignedAgentId: null,
      toolRing: null,
    };

    minion.summonTimer = this.scene.time.delayedCall(SUMMON_CIRCLE_LEAD_MS, () => {
      if (!minion.sprite?.active) {
        return;
      }
      minion.sprite.setAlpha(1);
      minion.badge?.setAlpha(1);
      const visualHeight = finalScale * MINION_CHAR_HEIGHT;
      const riseOffset = visualHeight * SUMMON_RISE_OFFSET_RATIO;
      minion.sprite.setScale(0);
      minion.sprite.y = spawnY + riseOffset;
      const glow = minion.sprite.preFX?.addGlow(0xfbbf24, 0, 0, false, 0.15, 20);
      if (glow) {
        this.scene.tweens.add({ targets: glow, outerStrength: 3, duration: 220, ease: 'Quad.easeOut' });
      }
      this.scene.tweens.add({
        targets: minion.sprite,
        scale: finalScale,
        y: spawnY,
        duration: SUMMON_RISE_MS,
        ease: 'Back.easeOut',
        onComplete: () => {
          minion.summoning = false;
          if (glow) {
            this.scene.tweens.add({
              targets: glow,
              outerStrength: 0,
              duration: 260,
              ease: 'Quad.easeIn',
              onComplete: () => minion.sprite?.preFX?.remove(glow),
            });
          }
        },
      });
    });

    this._scheduleFidget(minion);
    this._scheduleIdleWander(userId, minion);
    return minion;
  }

  /**
   * Stops and destroys one minion immediately (used when the visible count
   * shrinks while its fighter is still present — the fighter-leaving path
   * uses despawnAll instead, see its own docblock).
   *
   * @param {object} minion
   * @return {void}
   */
  _destroyOne(minion) {
    this._teardownMinion(minion);
    minion.sprite?.destroy();
    minion.badge?.destroy();
  }

  /**
   * Cancels a minion's own pending timers/tweens without destroying its
   * sprite or badge — shared by _destroyOne and despawnAll, which differ
   * only in whether they destroy those themselves afterward.
   *
   * @param {object} minion
   * @return {void}
   */
  _teardownMinion(minion) {
    minion.fidgetTimer?.remove();
    minion.idleTimer?.remove();
    minion.summonTimer?.remove();
    this.scene.tweens.killTweensOf(minion.sprite);
    this.scene.tweens.killTweensOf(minion.idleOffset);
    if (minion.summonCircle?.active) {
      minion.summonCircle.destroy();
    }
    minion.summonCircle = null;
    if (minion.toolRing?.active) {
      this.scene.tweens.killTweensOf(minion.toolRing);
      minion.toolRing.destroy();
    }
    minion.toolRing = null;
    minion.assignedAgentId = null;
  }

  /**
   * Creates the pulsing "using a tool right now" ring on a minion just
   * assigned to a busy agent_id — same visual language as
   * Charge.createChargingRing (color, pulse shape), sized to the minion's
   * OWN badge instead of a fighter's avatar. The circle itself is drawn
   * fresh every frame in _positionToolRing (not once here), since its
   * radius tracks the minion's current badge size, which can itself change
   * as the fighter's crowd/damage scale changes.
   *
   * @param {object} minion
   * @return {void}
   */
  _showToolRing(minion) {
    if (minion.toolRing) {
      return;
    }
    // Positioned at the minion's own current spot up front, not left at the
    // default (0,0) origin for the one frame before update() first calls
    // _positionToolRing — same reasoning _spawnOne's badge already follows.
    const ring = this.scene.add.graphics({ x: minion.sprite.x, y: minion.sprite.y }).setDepth(MINION_DEPTH_FRONT + 0.02);
    this.scene.tweens.add({
      targets: ring,
      alpha: { from: 0.9, to: 0.15 },
      scaleX: { from: 1.0, to: 1.18 },
      scaleY: { from: 1.0, to: 1.18 },
      duration: TIMINGS.chargeRingPulseMs,
      ease: 'Sine.easeInOut',
      yoyo: true,
      repeat: -1,
    });
    minion.toolRing = ring;
  }

  /**
   * Fades out and destroys a minion's tool-use ring when its assigned
   * agent_id goes idle again — same fade shape as _dismissSummonCircle,
   * instead of an instant pop.
   *
   * @param {object} minion
   * @return {void}
   */
  _dismissToolRing(minion) {
    const ring = minion.toolRing;
    minion.toolRing = null;
    if (!ring?.active) {
      return;
    }
    this.scene.tweens.killTweensOf(ring);
    this.scene.tweens.add({
      targets: ring,
      alpha: 0,
      duration: TOOL_RING_FADE_OUT_MS,
      onComplete: () => ring.destroy(),
    });
  }

  /**
   * Fades out and destroys a minion's still-playing summon-circle effect
   * early instead of letting its own ~1.75s clip run out at the fixed spot
   * it was spawned at. The circle has no relationship to the minion's own
   * subsequent movement — once the fighter starts walking (and this minion,
   * once its own ceremony finishes, starts trail-following it away from
   * that spot), the full-length clip visibly outlasts the whole spawn
   * ceremony (~SUMMON_CIRCLE_LEAD_MS + SUMMON_RISE_MS, well under half a
   * second) and is left behind as a stray ring at the old position — caught
   * live 2026-09-24 ("triệu hồi xong mà user chạy... vẫn thấy hiệu ứng vòng
   * vòng ở 1 chỗ trong khi agent con chạy mất rồi"). Safe to call more than
   * once; a no-op once the circle has already been dismissed or finished on
   * its own.
   *
   * @param {object} minion
   * @return {void}
   */
  _dismissSummonCircle(minion) {
    const circle = minion.summonCircle;
    minion.summonCircle = null;
    if (!circle?.active) {
      return;
    }
    this.scene.tweens.add({
      targets: circle,
      alpha: 0,
      duration: 200,
      onComplete: () => circle.destroy(),
    });
  }

  /**
   * Creates a fighter's leader trail-follow state if it doesn't already exist.
   *
   * @param {number|string} userId
   * @return {void}
   */
  _ensureLeader(userId) {
    if (this.leaders.has(userId)) {
      return;
    }
    const entry = this.scene.fighters.get(userId);
    const foot = entry?.sprite?.active ? this._footPosition(entry) : { x: 0, y: 0 };
    this.leaders.set(userId, { history: [], lastFoot: foot, lastUpdateAt: this.scene.time.now, moving: false });
  }

  /**
   * Removes a fighter's leader trail-follow state.
   *
   * @param {number|string} userId
   * @return {void}
   */
  _removeLeader(userId) {
    this.leaders.delete(userId);
  }

  /**
   * Schedules a minion's next independent idle wander hop — a pause, then a
   * short slow tweened move to a new nearby offset within its own home slot.
   *
   * @param {number|string} userId
   * @param {object} minion
   * @return {void}
   */
  _scheduleIdleWander(userId, minion) {
    const pause = Phaser.Math.Between(IDLE_PAUSE_MIN_MS, IDLE_PAUSE_MAX_MS);
    minion.idleTimer = this.scene.time.delayedCall(pause, () => {
      const entry = this.scene.fighters.get(userId);
      if (!minion.sprite?.active || !entry?.sprite?.active) {
        return;
      }
      const radius = this._currentSize(entry) * IDLE_WANDER_RADIUS_RATIO;
      const point = randomWanderPoint({ centerX: 0, centerY: 0, radiusX: radius, radiusY: radius });
      this.scene.tweens.add({
        targets: minion.idleOffset,
        x: point.x,
        y: point.y,
        duration: Phaser.Math.Between(IDLE_MOVE_MIN_MS, IDLE_MOVE_MAX_MS),
        ease: 'Sine.easeInOut',
        onComplete: () => this._scheduleIdleWander(userId, minion),
      });
    });
  }

  /**
   * A fighter's current effective on-screen size — baseSize (its size at
   * join time, what body/head bake their own local scale from) times the
   * container's own LIVE scaleX. Unlike entry.displaySize alone (which only
   * tracks the crowd-size bucket), this also picks up damage growth:
   * Fighter.fighterRestScale() folds both into the container's scaleX via
   * `(displaySize/baseSize) * damageScale`, so baseSize * scaleX recovers
   * the true current size. Minions must compute this themselves every frame
   * now that they are no longer container children.
   *
   * @param {object} entry the fighter entry from scene.fighters
   * @return {number}
   */
  _currentSize(entry) {
    return entry.baseSize * entry.sprite.scaleX;
  }

  /**
   * A fighter's current world-space foot position, following the same
   * `legH * scaleX` convention fighter/index.js and move-input.js already
   * use to project a container-local offset into world space as the
   * container grows/shrinks.
   *
   * @param {object} entry the fighter entry from scene.fighters
   * @return {{x: number, y: number}}
   */
  _footPosition(entry) {
    return { x: entry.sprite.x, y: entry.sprite.y + entry.legH * entry.sprite.scaleX };
  }

  /**
   * A minion's current world-space scale, derived from its fighter's
   * current effective size the same way body/head derive theirs from
   * baseSize (see _currentSize).
   *
   * @param {object} entry the fighter entry from scene.fighters
   * @return {number}
   */
  _minionScale(entry) {
    return (this._currentSize(entry) * MINION_SIZE_RATIO) / MINION_CHAR_HEIGHT;
  }

  /**
   * Positions/sizes/depths a minion's own avatar badge each frame, tucked
   * just above its head — tracks the minion's own current point/scale/
   * front-back depth rather than its fighter's, since a settled minion can
   * sit anywhere within its home slot or gather zone, not fixed relative to
   * the fighter itself. Also re-syncs the badge's own texture to the
   * fighter's CURRENT avatar every frame: the badge is created once at
   * spawn time from whatever `entry.head` shows then (real photo once
   * loaded, or the fallback icon in the meantime — see _spawnOne), and
   * Fighter's own async avatar load later calls `head.setTexture()` in
   * place on that same long-lived Image, with nothing telling an
   * already-spawned minion to look again — a minion that spawned in that
   * brief loading window would otherwise show the fallback for its entire
   * lifetime even once the real photo is ready. Comparing texture keys is
   * a cheap no-op once they match, so this costs nothing once caught up.
   *
   * @param {object} minion
   * @param {{x: number, y: number}} point the minion's current world position
   * @param {number} scale the minion's current sprite scale
   * @param {number} minionDepth the depth the minion sprite was just set to (front or back)
   * @param {object} entry the owning fighter entry from scene.fighters
   * @return {void}
   */
  _positionBadge(minion, point, scale, minionDepth, entry) {
    if (!minion.badge?.active) {
      return;
    }
    const headKey = entry.head?.texture?.key;
    if (headKey && minion.badge.texture.key !== headKey && this.scene.textures.exists(headKey)) {
      minion.badge.setTexture(headKey);
    }
    const visualHeight = scale * MINION_CHAR_HEIGHT;
    const badgePx = visualHeight * MINION_BADGE_SIZE_RATIO;
    minion.badge.setPosition(point.x, point.y - visualHeight * MINION_BADGE_OFFSET_RATIO);
    minion.badge.setDisplaySize(badgePx, badgePx);
    minion.badge.setDepth(minionDepth + 0.01);
  }

  /**
   * Repositions AND redraws a minion's tool-use ring each frame — same
   * per-frame treatment as _positionBadge, since minions are independent
   * world-space sprites that never stop moving. Redrawn rather than just
   * repositioned/scaled because its radius tracks the minion's current
   * badge size, unlike Charge.createChargingRing's fighter-side ring
   * (drawn once, since a fighter's avatar size is stable for its own
   * charging burst's short lifetime).
   *
   * @param {object} minion
   * @param {{x: number, y: number}} point the minion's current world position
   * @param {number} scale the minion's current sprite scale
   * @param {number} minionDepth the depth the minion sprite was just set to (front or back)
   * @return {void}
   */
  _positionToolRing(minion, point, scale, minionDepth) {
    if (!minion.toolRing?.active) {
      return;
    }
    const visualHeight = scale * MINION_CHAR_HEIGHT;
    const badgePx = visualHeight * MINION_BADGE_SIZE_RATIO;
    const badgeY = point.y - visualHeight * MINION_BADGE_OFFSET_RATIO;
    const r = badgePx / 2 + Math.max(2, badgePx * TOOL_RING_MARGIN_RATIO);
    minion.toolRing.clear();
    minion.toolRing.lineStyle(2, TOOL_RING_COLOR, 1);
    minion.toolRing.strokeCircle(0, 0, r);
    minion.toolRing.setPosition(point.x, badgeY);
    minion.toolRing.setDepth(minionDepth + 0.02);
  }

  /**
   * Switches a minion between its Idle and Walk loops based on how fast it
   * is currently moving, unless it's mid-fidget, mid-fight, or mid-summon
   * (all left alone to finish on their own terms).
   *
   * @param {object} minion
   * @param {number} speed current sampled speed, px/s
   * @return {void}
   */
  _updateMinionAnim(minion, speed) {
    if (minion.fidgeting || minion.fighting || minion.summoning) {
      return;
    }
    const nextState = speed > WALK_SPEED_THRESHOLD_PX_PER_S ? 'walk' : 'idle';
    if (minion.animState !== nextState) {
      minion.animState = nextState;
      minion.sprite.play(`${minion.typeKey}-${nextState}`);
    }
  }

  /**
   * Schedules a minion's next cosmetic idle fidget (a one-shot Attack1/
   * Attack2 flourish — "khè khè / múa múa", never real combat). Skips and
   * reschedules if the minion is walking or mid-fight when the timer fires,
   * so the fidget only ever plays while genuinely settled.
   *
   * @param {object} minion
   * @return {void}
   */
  _scheduleFidget(minion) {
    const delay = Phaser.Math.Between(FIDGET_MIN_MS, FIDGET_MAX_MS);
    minion.fidgetTimer = this.scene.time.delayedCall(delay, () => {
      if (!minion.sprite?.active) {
        return;
      }
      if (minion.animState !== 'idle' || minion.fighting) {
        this._scheduleFidget(minion);
        return;
      }
      const anim = Phaser.Math.Between(0, 1) === 0 ? 'attack1' : 'attack2';
      minion.fidgeting = true;
      minion.sprite.play(`${minion.typeKey}-${anim}`);
      minion.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        minion.fidgeting = false;
        if (minion.sprite?.active) {
          minion.sprite.play(`${minion.typeKey}-idle`);
        }
        this._scheduleFidget(minion);
      });
    });
  }

  /**
   * Finds every pair of minions belonging to DIFFERENT fighters currently
   * within FIGHT_PROXIMITY_PX of each other and not already busy/cooling
   * down, and triggers a cosmetic clash between the first such pair per
   * minion this frame.
   *
   * @param {number} time
   * @param {Array<{userId: number|string, minion: object}>} allActive every currently-positioned minion this frame
   * @return {void}
   */
  _updateFights(time, allActive) {
    for (let a = 0; a < allActive.length; a++) {
      const entryA = allActive[a];
      if (!this._isFightEligible(entryA.minion, time)) {
        continue;
      }
      for (let b = a + 1; b < allActive.length; b++) {
        const entryB = allActive[b];
        if (entryA.userId === entryB.userId || !this._isFightEligible(entryB.minion, time)) {
          continue;
        }
        const dist = Phaser.Math.Distance.Between(
          entryA.minion.sprite.x, entryA.minion.sprite.y,
          entryB.minion.sprite.x, entryB.minion.sprite.y,
        );
        if (dist <= FIGHT_PROXIMITY_PX) {
          this._triggerFight(entryA.minion, entryB.minion);
          break;
        }
      }
    }
  }

  /**
   * @param {object} minion
   * @param {number} time
   * @return {boolean} whether this minion is free to start a new clash right now
   */
  _isFightEligible(minion, time) {
    return minion.sprite?.active && !minion.fighting && !minion.fidgeting && !minion.summoning && time >= minion.fightCooldownUntil;
  }

  /**
   * Plays a one-shot Attack1/Attack2 clash between two minions from
   * different fighters, facing each other, plus a one-shot explosion burst
   * + fire-toned particle scatter at their midpoint (see _spawnClashVfx) —
   * purely cosmetic, never touches damage/HP. Both cool down for
   * FIGHT_COOLDOWN_MS once their own animation completes, independently
   * (their strips may differ in length).
   *
   * @param {object} minionA
   * @param {object} minionB
   * @return {void}
   */
  _triggerFight(minionA, minionB) {
    const aOnLeft = minionA.sprite.x <= minionB.sprite.x;
    minionA.sprite.setFlipX(!aOnLeft);
    minionB.sprite.setFlipX(aOnLeft);
    const anim = Phaser.Math.Between(0, 1) === 0 ? 'attack1' : 'attack2';
    for (const minion of [minionA, minionB]) {
      minion.fighting = true;
      minion.sprite.play(`${minion.typeKey}-${anim}`);
      minion.sprite.once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => {
        minion.fighting = false;
        minion.fightCooldownUntil = this.scene.time.now + FIGHT_COOLDOWN_MS;
        if (minion.sprite?.active) {
          minion.sprite.play(`${minion.typeKey}-idle`);
        }
      });
    }
    this._spawnClashVfxSequence(
      (minionA.sprite.x + minionB.sprite.x) / 2,
      (minionA.sprite.y + minionB.sprite.y) / 2,
      (minionA.sprite.scale + minionB.sprite.scale) / 2,
    );
  }

  /**
   * Several staggered explosion bursts around a clash's midpoint instead of
   * a single one, so the melee reads as sustained chaos for roughly the
   * clash animation's own length, not one quick flash — "nổ nhiều hơn xíu,
   * vài vụ nổ quanh khu vực đánh nhau". Each burst gets its own small random
   * position jitter (still centered on the midpoint) and a slightly jittered
   * delay, so the sequence doesn't read as one obviously-looped effect.
   *
   * @param {number} x
   * @param {number} y
   * @param {number} scale the clashing minions' own average current scale
   * @return {void}
   */
  _spawnClashVfxSequence(x, y, scale) {
    const jitterRadius = scale * MINION_CHAR_HEIGHT * CLASH_VFX_JITTER_RATIO;
    for (let i = 0; i < CLASH_VFX_BURST_COUNT; i++) {
      const delay = Math.max(0, i * CLASH_VFX_STAGGER_MS + Phaser.Math.Between(-40, 40));
      this.scene.time.delayedCall(delay, () => {
        const jx = x + Phaser.Math.Between(-jitterRadius, jitterRadius);
        const jy = y + Phaser.Math.Between(-jitterRadius, jitterRadius);
        this._spawnClashVfxBurst(jx, jy, scale);
      });
    }
  }

  /**
   * A one-shot explosion burst (one of MINION_CLASH_EFFECTS, picked at
   * random for variety) plus a small fire-toned particle scatter. Both
   * self-destroy once their own animation/particle lifespan finishes —
   * nothing here needs its own explicit teardown call elsewhere, unlike a
   * minion's own long-lived timers/tweens.
   *
   * @param {number} x
   * @param {number} y
   * @param {number} scale so the burst reads proportionate to the minions clashing
   * @return {void}
   */
  _spawnClashVfxBurst(x, y, scale) {
    const effect = MINION_CLASH_EFFECTS[Phaser.Math.Between(0, MINION_CLASH_EFFECTS.length - 1)];
    const burstKey = `${effect.key}-burst`;
    const burst = this.scene.add
      .sprite(x, y, burstKey)
      .setScale(scale * 1.4)
      .setDepth(MINION_DEPTH_FRONT + 0.1)
      .setBlendMode(Phaser.BlendModes.ADD);
    burst.play(burstKey).once(Phaser.Animations.Events.ANIMATION_COMPLETE, () => burst.destroy());

    const particles = this.scene.add.particles(x, y, TextureKey.SPARK, {
      tint: { onEmit: () => Phaser.Math.RND.pick(CLASH_PARTICLE_TINTS) },
      scale: { start: scale * 5, end: 0 },
      alpha: { start: 0.9, end: 0 },
      speed: { min: 25, max: 80 },
      angle: { min: 0, max: 360 },
      lifespan: { min: 160, max: 300 },
      frequency: -1,
      quantity: CLASH_PARTICLE_COUNT,
      blendMode: Phaser.BlendModes.ADD,
    });
    particles.explode(CLASH_PARTICLE_COUNT);
    this.scene.time.delayedCall(CLASH_PARTICLE_CLEANUP_MS, () => {
      if (particles.scene) {
        particles.destroy();
      }
    });
  }

  /**
   * Registers every MINION_TYPES and MINION_CLASH_EFFECTS animation against
   * its own individually-loaded spritesheet, idempotent.
   *
   * @return {void}
   */
  _ensureAnims() {
    for (const type of [...MINION_TYPES, ...MINION_CLASH_EFFECTS]) {
      for (const [anim, info] of Object.entries(type.animFiles)) {
        const key = `${type.key}-${anim}`;
        if (!this.scene.anims.exists(key)) {
          this.scene.anims.create({
            key,
            frames: this.scene.anims.generateFrameNumbers(key, { start: 0, end: info.count - 1 }),
            frameRate: info.rate,
            repeat: info.loop ? -1 : 0,
          });
        }
      }
    }
  }

  /**
   * Stops every pending timer/tween for every fighter's minions — called on
   * scene shutdown. Does not destroy the sprites themselves: Phaser's own
   * scene teardown destroys the full display list.
   *
   * @return {void}
   */
  destroy() {
    for (const list of this.byUser.values()) {
      for (const minion of list) {
        this._teardownMinion(minion);
      }
    }
    this.byUser.clear();
    this.leaders.clear();
  }
}
