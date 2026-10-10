/**
 * Shows the DOM HUD once the battlefield has loaded: numbers each panel
 * (`--i`, the CSS stagger) and marks the HUD ready, so the nav, Team Damage,
 * boss plate, board and feed slide in one after another instead of sitting
 * on screen before the field behind them has drawn. Team and board count as
 * panels of their own inside the `.bf-stats` wrapper.
 *
 * @param {HTMLElement} hud The `.bf-hud` element.
 * @return {void}
 */
export function revealHud(hud) {
  // .bf-stats only groups team + board (a box of its own on a phone, none on
  // a desktop), so its panels are numbered, not the wrapper
  hud.querySelectorAll('.bf-hud-in > :not(.bf-stats), .bf-stats > section')
    .forEach((el, i) => el.style.setProperty('--i', String(i)));
  hud.classList.add('ready');
}
