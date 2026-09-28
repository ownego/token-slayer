/**
 * Binds a set of bus handlers and returns the one function that removes them
 * all. Scenes call it on both SHUTDOWN and DESTROY (game.destroy() fires only
 * the latter), so it must be safe to call twice.
 *
 * @param {{ on: function(string, Function): void, off: function(string, Function): void }} bus
 * @param {Record<string, Function>} handlers
 * @return {function(): void}
 */
export function bindBus(bus, handlers) {
  const pairs = Object.entries(handlers);
  pairs.forEach(([event, fn]) => bus.on(event, fn));
  let bound = true;
  return () => {
    if (!bound) {
      return;
    }
    bound = false;
    pairs.forEach(([event, fn]) => bus.off(event, fn));
  };
}
