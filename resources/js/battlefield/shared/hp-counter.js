/**
 * One tween per HP bar: a new target retargets from the value on screen, so a
 * burst of hits can't stack tweens that all write the same text every frame.
 *
 * @param {{ startTween: function({from: number, to: number, onUpdate: function(number): void}): {stop: function(): void}, render: function(number): void, initial: number }} deps
 * @return {{ set: function(number): void, reset: function(number): void, stop: function(): void }}
 */
export function createHpCounter({ startTween, render, initial }) {
  let shown = initial;
  let rendered = null;
  let running = null;

  const show = value => {
    shown = value;
    const rounded = Math.round(value);
    if (rounded !== rendered) {
      rendered = rounded;
      render(rounded);
    }
  };

  return {
    /**
     * Retargets the counter at a new HP value, stopping whatever tween was
     * already running so a burst of hits never stacks more than one.
     *
     * @param {number} target
     * @return {void}
     */
    set(target) {
      running?.stop();
      running = startTween({ from: shown, to: target, onUpdate: show });
    },
    /**
     * Stops the running tween and shows `value` immediately — used on
     * respawn so the counter never tweens up from the dead boss's 0.
     *
     * @param {number} value
     * @return {void}
     */
    reset(value) {
      running?.stop();
      running = null;
      rendered = null;
      show(value);
    },
    /**
     * Stops the running tween without changing the currently shown value.
     *
     * @return {void}
     */
    stop() {
      running?.stop();
      running = null;
    },
  };
}
