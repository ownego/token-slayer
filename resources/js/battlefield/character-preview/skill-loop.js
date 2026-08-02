/**
 * Sticky skill-selection state machine: looping skills (idle/walk) play
 * once and stay playing via Phaser's own repeat: -1; one-shot skills
 * (attacks/death) play once, hold, then replay via scheduleReplay — forever,
 * until a new select() or cancel() bumps the token and silently orphans the
 * old chain's pending callback.
 *
 * @param {{ playAnimation: Function, scheduleReplay: Function, holdMs?: number }} deps
 * @return {{ select: Function, cancel: Function }}
 */
export function createSkillLoop({ playAnimation, scheduleReplay, holdMs = 1000 }) {
  let token = 0;

  function select(skill) {
    token += 1;
    const myToken = token;

    const step = () => {
      if (myToken !== token) {
        return;
      }
      playAnimation(skill, () => {
        if (myToken !== token || skill.loop) {
          return;
        }
        scheduleReplay(step, holdMs);
      });
    };

    step();
  }

  function cancel() {
    token += 1;
  }

  return { select, cancel };
}
