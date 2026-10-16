import Phaser from 'phaser';
import { registerFighterAnimations } from './animations.js';
import { CUSTOM_FRAME, customFighterType, sheetLayout, stripUrls } from './custom-fighter.js';

/**
 * Textures being built right now, by custom fighter key. Two events for the same
 * fighter (a join and the boss change right behind it) must share one load.
 * @type {Map<string, Promise<void>>}
 */
const pending = new Map();

/**
 * The browser pieces loading needs, as a default the tests replace.
 * @type {{loadImage: function(string): Promise<HTMLImageElement>, makeCanvas: function(number, number): HTMLCanvasElement}}
 */
const BROWSER = {
  loadImage: url => new Promise((resolve, reject) => {
    const image = new Image();
    // strips on a bucket or CDN are another origin: without CORS the stacked canvas could not be read back (the heel probe)
    if (/^https?:\/\//.test(url)) {
      image.crossOrigin = 'anonymous';
    }
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error(`custom fighter strip failed to load: ${url}`));
    image.src = url;
  }),
  makeCanvas: (width, height) => Object.assign(document.createElement('canvas'), { width, height }),
};

/**
 * Loads a user's custom fighter into the scene: fetches its four strips, stacks
 * them into one texture of named 100 px frames (the names the roster atlas uses,
 * so the fighter code reads both the same), and registers its animations. The
 * texture is the game's, not the scene's, so it survives a rotate-restart and a
 * second request for the same version costs nothing. The strips are same-origin
 * paths, which keeps the canvas readable for the heel measurement.
 *
 * @param {Phaser.Scene} scene
 * @param {number|string} userId
 * @param {{version: string, urlBase: string, animations: object}} spec A normalised custom character.
 * @param {{loadImage?: Function, makeCanvas?: Function}} [browser] Replaceable browser pieces, for tests.
 * @return {Promise<object>} The fighter type, ready to hand to the fighter code.
 */
export async function loadCustomFighter(scene, userId, spec, browser = {}) {
  const ftype = customFighterType(userId, spec);

  if (!scene.textures.exists(ftype.texture)) {
    if (!pending.has(ftype.key)) {
      const build = buildTexture(scene, ftype, spec, { ...BROWSER, ...browser }).finally(() => pending.delete(ftype.key));
      pending.set(ftype.key, build);
    }
    await pending.get(ftype.key);
  }
  registerFighterAnimations(scene, ftype);

  return ftype;
}

/**
 * Fetches the strips and adds the stacked texture with every frame named.
 *
 * @param {Phaser.Scene} scene
 * @param {{texture: string}} ftype
 * @param {{urlBase: string, animations: object}} spec
 * @param {{loadImage: Function, makeCanvas: Function}} browser
 * @return {Promise<void>}
 */
async function buildTexture(scene, ftype, spec, browser) {
  const layout = sheetLayout(spec.animations);
  const urls = stripUrls(spec);
  const images = await Promise.all(layout.rows.map(({ name }) => browser.loadImage(urls[name])));

  const canvas = browser.makeCanvas(layout.width, layout.height);
  const context = canvas.getContext('2d');
  layout.rows.forEach(({ y }, index) => context.drawImage(images[index], 0, y));

  const texture = scene.textures.addCanvas(ftype.texture, canvas);
  for (const { name, x, y } of layout.frames) {
    texture.add(`${ftype.key}-${name}`, 0, x, y, CUSTOM_FRAME, CUSTOM_FRAME);
  }
  texture.setFilter(Phaser.Textures.FilterMode.NEAREST);
}
