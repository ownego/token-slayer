/**
 * Phaser re-sorts the whole display list on every depth write, even an
 * unchanged one; per-frame callers go through this instead.
 *
 * @param {{ depth: number, setDepth: function(number): object }} obj
 * @param {number} depth
 * @return {boolean} whether the depth was written
 */
export function setDepthIfChanged(obj, depth) {
  if (obj.depth === depth) {
    return false;
  }
  obj.setDepth(depth);
  return true;
}
