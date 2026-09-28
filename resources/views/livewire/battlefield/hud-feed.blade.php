{{-- Activity feed, wired by resources/js/battlefield/hud/feed-view.js
     (createFeedView) from index.js's bootBattlefield. Lines are
     pointer-events:none (see .bf-feed .lines in battlefield-hud.css) so
     fighters under the feed stay clickable; only the history panel
     (revealed on :hover, in CSS) takes the pointer. --}}
<div class="hist" id="bf-feed-hist"></div>
<div class="earlier" id="bf-feed-earlier"></div>
<div class="lines" id="bf-feed-lines"></div>
