import './echo';
import { battlefieldHud } from './battlefield/hud/index.js';
import { agoTicker, clawdBuddyPanel, fighterSheetShell, sparkChart } from './battlefield/sheet/index.js';
import { accountCard } from './battlefield/sheet/account-card.js';
import { modelPicker, periodTabs } from './battlefield/sheet/pickers.js';
import { miniStage } from './battlefield/sheet/mini-stage.js';
import { characterStage } from './battlefield/sheet/character-stage.js';

// Registered before Livewire's bundled Alpine calls Alpine.start() (this
// script loads via @vite in <head>; @livewireScripts starts Alpine later in
// <body>), so x-data="battlefieldHud()"/"fighterSheetShell()"/
// "characterStage(...)" always resolve — even before the lazily-imported
// battlefield chunk below (Phaser + bus.js) finishes loading, and even
// though the Character tab's own markup only ever enters the DOM via a
// later Livewire morph (a plain <script> tag inserted that way never
// executes, unlike Alpine's own component registry). Neither hud/index.js
// nor any sheet/ module imports Phaser, so this stays a plain, synchronous,
// static import.
document.addEventListener('alpine:init', () => {
  window.Alpine.data('battlefieldHud', battlefieldHud);
  window.Alpine.data('fighterSheetShell', fighterSheetShell);
  window.Alpine.data('sparkChart', sparkChart);
  window.Alpine.data('agoTicker', agoTicker);
  window.Alpine.data('accountCard', accountCard);
  window.Alpine.data('periodTabs', periodTabs);
  window.Alpine.data('modelPicker', modelPicker);
  window.Alpine.data('miniStage', miniStage);
  window.Alpine.data('clawdBuddyPanel', clawdBuddyPanel);
  window.Alpine.data('characterStage', characterStage);
});

// Phaser is ~1 MB — only load when battlefield canvas is present
window.__battlefieldModule = import('./battlefield');
window.__battlefieldModule.then(m => { window.bootBattlefield = m.bootBattlefield; });
