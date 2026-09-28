/**
 * Shows the DOM HUD once the battlefield has loaded: numbers each panel
 * (`--i`, the CSS stagger) and marks the HUD ready, so the nav, Team Damage,
 * boss plate, board and feed slide in one after another instead of sitting
 * on screen before the field behind them has drawn.
 *
 * @param {HTMLElement} hud The `.bf-hud` element.
 * @return {void}
 */
export function revealHud(hud) {
  hud.querySelectorAll('.bf-hud-in > *').forEach((el, i) => el.style.setProperty('--i', String(i)));
  hud.classList.add('ready');
}
