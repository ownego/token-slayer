// WrapperHtml.kt
package app.tokenslayer.ui

private fun esc(s: String): String = s
    .replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;")
    .replace("\"", "&quot;").replace("'", "&#39;")

fun iframeWrapperHtml(signedUrl: String, serverOrigin: String): String = """
<!DOCTYPE html><html><head><meta charset="utf-8">
<style>html,body{margin:0;padding:0;height:100vh;width:100%;overflow:hidden;background:#0f172a}
iframe{position:absolute;inset:0;width:100%;height:100%;border:0;display:block}</style>
</head><body>
<iframe id="ts" src="${esc(signedUrl)}"></iframe>
<script>
window.addEventListener('message', function (e) {
  var f = document.getElementById('ts');
  if (!f || e.source !== f.contentWindow) return;
  if (e.origin !== '${esc(serverOrigin)}') return;
  if (window.__tokenSlayerRelay) window.__tokenSlayerRelay(JSON.stringify(e.data));
});
</script></body></html>
""".trimIndent()

fun signedOutHtml(): String = """
<!DOCTYPE html><html><body style="font-family:sans-serif;padding:1rem;color:#e2e8f0;background:#0f172a">
<h2>Token Slayer</h2>
<p>Sign in with Slack to see your battlefield, get hit notifications, and install Claude Code hooks.</p>
<button id="signin" style="padding:.5rem 1rem">Sign in with Slack</button>
<script>document.getElementById('signin').addEventListener('click',function(){
  if(window.__tokenSlayerRelay)window.__tokenSlayerRelay(JSON.stringify({type:'sign-in-requested'}));});</script>
</body></html>
""".trimIndent()

fun errorHtml(message: String): String = """
<!DOCTYPE html><html><body style="font-family:sans-serif;padding:1rem;color:#e2e8f0;background:#0f172a">
<h3>Couldn't load Token Slayer</h3>
<p style="color:#f87171">${esc(message)}</p>
<button id="retry">Retry</button>
<script>document.getElementById('retry').addEventListener('click',function(){
  if(window.__tokenSlayerRelay)window.__tokenSlayerRelay(JSON.stringify({type:'retry-requested'}));});</script>
</body></html>
""".trimIndent()
