{{-- Top damage leaderboard rows, wired by resources/js/battlefield/hud/board-view.js
     (createBoardView) from the boss/scene layer, not Alpine — the board only
     changes on real hits/spawns, which arrive through the Phaser bus. --}}
<div class="tp-title">TOP DAMAGE <span id="bf-board-boss"></span></div>
<div class="board">
    <ol id="bf-board-rows" role="list" aria-label="Top damage rows"></ol>
</div>
<div class="more" id="bf-board-more"></div>
