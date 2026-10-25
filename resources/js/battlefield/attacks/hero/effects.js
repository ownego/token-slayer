/**
 * Owns everything a hero move puts on the field: its sprites, its delays and
 * its repeating timers, on the scene rather than on the fighter, so a fighter
 * that leaves or swaps look mid-move never cuts the move's `onImpact`.
 *
 * Its clock is the tween manager's, not `scene.time`: the kill ceremony slows
 * tweens and animations, and a killing move's contacts must stay in step with
 * its flight. `clear()` (the kill ceremony starting) removes it all but still
 * lets an already scheduled main contact land; a scene shutdown destroys the
 * tween manager, and with it any contact not yet landed.
 */
export class HeroEffects {
  /**
   * @param {Phaser.Scene} scene The battlefield scene.
   * @param {number} cap Live objects above which big moves play their main contact only.
   */
  constructor(scene, cap) {
    this.scene = scene;
    this.cap = cap;
    this.objects = new Set();
    this.timers = new Set();
  }

  /**
   * Tracks a display object so `clear()` can remove it; it stops counting once destroyed.
   *
   * @param {Phaser.GameObjects.GameObject} obj
   * @return {Phaser.GameObjects.GameObject} The same object.
   */
  track(obj) {
    this.objects.add(obj);
    obj.once?.('destroy', () => this.objects.delete(obj));
    return obj;
  }

  /**
   * The hero effect objects on the field right now.
   *
   * @return {number}
   */
  get live() {
    return this.objects.size;
  }

  /**
   * Whether the field is busy enough that big moves should play their main contact only.
   *
   * @return {boolean}
   */
  get capped() {
    return this.objects.size >= this.cap;
  }

  /**
   * Runs `fn` after `ms` on the hero clock (tweens). A main contact survives `clear()`.
   *
   * @param {number} ms
   * @param {Function} fn
   * @param {{main?: boolean}} [options]
   * @return {void}
   */
  schedule(ms, fn, { main = false } = {}) {
    const entry = { main, timer: null };
    entry.timer = this.scene.tweens.addCounter({
      from: 0,
      to: 1,
      duration: Math.max(1, ms),
      onComplete: () => {
        this.timers.delete(entry);
        fn();
      },
    });
    this.timers.add(entry);
  }

  /**
   * Calls `onUpdate(progress)` every frame for `ms` (progress 0..1 by elapsed
   * time, not by frame count, so a slow screen still sweeps the whole way).
   *
   * @param {number} ms
   * @param {Function} onUpdate
   * @return {void}
   */
  sweep(ms, onUpdate) {
    const entry = { main: false, timer: null };
    entry.timer = this.scene.tweens.addCounter({
      from: 0,
      to: 1,
      duration: Math.max(1, ms),
      onUpdate: tween => onUpdate(tween.getValue()),
      onComplete: () => this.timers.delete(entry),
    });
    this.timers.add(entry);
  }

  /**
   * Runs `fn` every `ms` until the returned stop function is called or `clear()` runs.
   *
   * @param {number} ms
   * @param {Function} fn
   * @return {Function} Stops the loop.
   */
  every(ms, fn) {
    const entry = { main: false, timer: null };
    entry.timer = this.scene.tweens.addCounter({ from: 0, to: 1, duration: Math.max(1, ms), repeat: -1, onRepeat: fn });
    this.timers.add(entry);
    return () => {
      entry.timer.destroy();
      this.timers.delete(entry);
    };
  }

  /**
   * Removes every tracked object and every pending timer except main contacts.
   *
   * @return {void}
   */
  clear() {
    for (const entry of [...this.timers]) {
      if (!entry.main) {
        entry.timer.destroy();
        this.timers.delete(entry);
      }
    }
    for (const obj of [...this.objects]) {
      this.scene.tweens.killTweensOf(obj);
      obj.destroy();
    }
    this.objects.clear();
  }
}
