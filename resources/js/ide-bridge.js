/**
 * Runs only inside the VSCode webview iframe (embed=ide). Forwards a
 * whitelist of Echo events on the public `battlefield` channel to the
 * extension host via the VSCode webview API.
 *
 * Implementation lands in a later task; this stub exists so that
 * @vite('resources/js/ide-bridge.js') resolves and the layout test can
 * verify the script tag is rendered.
 */
export {};
