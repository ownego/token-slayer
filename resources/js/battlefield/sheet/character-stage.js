// The fighter sheet's Character tab, ported from the approved mockup's own
// script (docs/superpowers/mockups/2026-09-27-battlefield-redesign/
// fighter-sheet.html): roster slots with hover peek, the stage with its
// summon/attack/death FX against a boss, the move hotbar and the Equip
// button. The mockup's fake battlefield is replaced by the real one: Equip
// persists through $wire.equip and then dispatches `fighter-equipped`, which
// fighterSheetShell answers by folding the sheet and focusing the camera.
import { BOX, ROSTER, TYPE_LINE } from './sheet-roster.js';
import { BY_KEY, faceColor, frameIn, moves, paint, paintCharge, playAnim, pretty, standOn, strip, wait } from './sprite-strip.js';
import { heelOf, sparkField, sparkPoint } from './stage-sparks.js';

/**
 * The stage FX strips, all existing battlefield assets.
 *
 * @type {Object<string, string>}
 */
const FX = {
  circle: '/assets/battlefield/companions/necromancer/summon-circle.png',
  burst: '/assets/battlefield/companions/necromancer/appear-burst.png',
  bossIdle: '/assets/battlefield/bosses/flying-demon-xzany/idle.png',
  bossHurt: '/assets/battlefield/bosses/flying-demon-xzany/hurt.png',
  spark: '/assets/battlefield/companions/minion-clash/burst1.png',
};

/**
 * Feedback tiers: every event maps to one, so intensity stays proportional.
 *
 * @type {Object<string, {trauma: number, stop: number, debris: number, squash: number}>}
 */
const TIER = {
  small: { trauma: 0.18, stop: 0, debris: 0, squash: 0.08 },
  medium: { trauma: 0.42, stop: 80, debris: 8, squash: 0.2 },
  large: { trauma: 0.8, stop: 140, debris: 16, squash: 0.32 },
};

/**
 * The stage floor line, in px above the stage's bottom edge.
 *
 * @type {number}
 */
const FLOOR = 44;

/**
 * How long Equip's own shockwave and banner play before the sheet folds away.
 *
 * @type {number}
 */
const EQUIP_FOLD_DELAY_MS = 900;

/**
 * When Equip persists, after the click: the fold delay, the sheet's own
 * 350ms fold and the camera's 950ms push-in (shared/camera-focus.js) — so
 * every client's field plays the death/summon swap while this camera is on it.
 *
 * @type {number}
 */
const EQUIP_PERSIST_DELAY_MS = EQUIP_FOLD_DELAY_MS + 350 + 950;

/**
 * Alpine.data() component for the Character tab.
 *
 * @param {string} startingKey The viewer's equipped fighter key.
 * @param {Object<string, Array<{user_id: number, handle: string}>>} roommates Everyone by the fighter they play.
 * @param {string} handle The viewer's own display handle.
 * @param {number} me The viewer's user id, left out of the teammates.
 * @return {object}
 */
export function characterStage(startingKey, roommates, handle, me) {
  return {
    cleanup: [],
    init() {
      // Equip persists after the sheet has closed, and Livewire's $wire magic
      // resolves its component lazily from this (by then detached) element —
      // so remember the component's id now and find it again at call time
      const componentId = this.$wire.$id;
      this.cleanup = mountCharacterStage(this.$el, {
        equipped: startingKey,
        roommates: roommates || {},
        handle,
        me,
        equip: key => window.Livewire.find(componentId)?.equip(key),
        dispatch: (name, detail) => this.$dispatch(name, detail),
      });
    },
    destroy() {
      this.cleanup.forEach(fn => fn());
    },
  };
}

/**
 * Wires the Character tab onto its already-rendered markup.
 *
 * @param {HTMLElement} root The tab's root element.
 * @param {{equipped: string, roommates: object, handle: string, me?: number, equip: function(string): Promise, dispatch: function(string, object=): void}} opts
 * @return {Array<function(): void>} Teardown callbacks.
 */
export function mountCharacterStage(root, { equipped: startingKey, roommates: everyone, handle, me, equip, dispatch }) {
  // teammates only: CharacterRoommates lists everyone on a fighter, the viewer included
  const roommates = Object.fromEntries(Object.entries(everyone).map(([key, users]) => [key, users.filter(u => Number(u.user_id) !== Number(me))]));
  const $$ = id => root.querySelector(`#${id}`);
  const cleanup = [];
  const on = (target, type, fn, opts) => {
    target.addEventListener(type, fn, opts);
    cleanup.push(() => target.removeEventListener(type, fn, opts));
  };
  const reduced = typeof matchMedia === 'function' && matchMedia('(prefers-reduced-motion: reduce)').matches;
  const known = key => Boolean(BY_KEY[key]);

  let equipped = known(startingKey) ? startingKey : ROSTER[0].key;
  let previewing = equipped;
  let activeMove = 'idle';
  let seq = 0; // bumps on every new selection so stale chains stop
  let beforeEquip = equipped;

  // stage: whole-number scale (crisp pixels) giving a ~116px body, feet on the portal pad
  function stageScale(key) {
    const [, y0, , y1] = BOX[key];
    const stage = $$('show-stage');
    const stageH = stage?.clientHeight || 330;
    const stageW = stage?.clientWidth || 600;
    const target = Math.max(56, Math.min(94, stageH * 0.17, stageW * 0.23));

    return Math.max(2, Math.min(5, Math.round(target / (y1 - y0))));
  }

  function frameStage(el, key) {
    const [, y0, , y1] = BOX[key];
    const sc = stageScale(key);
    if (el.id === 'show-sprite') {
      const aura = root.querySelector('#show-stage .aura');
      if (aura) {
        aura.style.bottom = `${(FLOOR + (y1 - y0) * sc / 2 - 120).toFixed(1)}px`;
      }
      $$('flames')?.style.setProperty('--body', `${((y1 - y0) * sc).toFixed(0)}px`);
      const av = $$('stage-avatar');
      if (av) {
        // battlefield proportions: body = 18 sprite px x scale, avatar = 0.85 x body, centre 38/48 of a body above the head
        const body = 18 * sc;
        const size = Math.round(body * 0.85);
        av.style.width = av.style.height = `${size}px`;
        av.style.fontSize = `${Math.round(size * 0.5)}px`;
        av.style.bottom = `${(FLOOR + (y1 - y0) * sc + body * 38 / 48 - size / 2).toFixed(1)}px`;
      }
    }
    standOn(el, key, sc, FLOOR);
  }

  // ---- roster ----
  const grid = $$('roster-grid');
  // the real avatar over the initial; the initial stays underneath if the image fails
  const mateFace = (mate, cls = 'mate') => `<span class="${cls}" style="background:${faceColor(mate.user_id)}" title="${escapeHtml(mate.handle)}">${escapeHtml(mate.handle.charAt(0).toUpperCase())}${mate.avatar ? `<img src="${escapeHtml(mate.avatar)}" alt="" loading="lazy" onerror="this.remove()">` : ''}</span>`;
  ROSTER.forEach(c => {
    const b = document.createElement('button');
    b.className = 'slot';
    b.type = 'button';
    b.setAttribute('role', 'option');
    b.dataset.key = c.key;
    b.dataset.type = c.type;
    b.style.setProperty('--d', grid.children.length);
    b.setAttribute('aria-label', pretty(c.key));
    b.innerHTML = `<span class="face-sprite"></span><span class="nm">${pretty(c.key)}</span>${c.key === equipped ? '<span class="tag">YOU</span>' : ''}`;
    paint(b.querySelector('.face-sprite'), c.key, 'idle', { hoverOnly: true });
    frameIn(b.querySelector('.face-sprite'), c.key, { maxW: 46, maxH: 36, cyPct: 40 });
    const mates = roommates[c.key] || [];
    if (mates.length) {
      b.insertAdjacentHTML('beforeend', `<span class="mates">${mates.slice(0, 2).map(m => mateFace(m)).join('')}${mates.length > 2 ? `<span class="mate more">+${mates.length - 2}</span>` : ''}</span>`);
      b.dataset.mates = mates.map(m => m.handle).join(', ');
    }
    on(b, 'click', () => select(c.key));
    grid.appendChild(b);
  });
  $$('roster-count').textContent = `${ROSTER.length} fighters`;

  function paintAlso(key) {
    const mates = roommates[key] || [];
    // two faces and names, the rest counted, so a popular fighter's line never wraps over the stage
    const shown = mates.slice(0, 2);
    const more = mates.length - shown.length;
    $$('also').innerHTML = mates.length
      ? `${shown.map(m => mateFace(m)).join('')}<b>${shown.map(m => escapeHtml(m.handle)).join(', ')}${more > 0 ? ` +${more}` : ''}</b>&nbsp;${mates.length === 1 ? 'fights' : 'fight'} as this too`
      : '';
  }

  // ---- stage FX ----
  const bossStrip = which => {
    const hurt = which === 'hurt';

    return strip($$('boss'), hurt ? FX.bossHurt : FX.bossIdle, 4, hurt ? 10 : 8, { loop: !hurt, fw: 81, fh: 71 });
  };
  bossStrip('idle');
  for (let i = 0; i < 16; i++) {
    const t = document.createElement('span');
    t.style.setProperty('--i', i);
    $$('tick-ring').appendChild(t);
  }
  const restart = (el, cls) => {
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  };

  // screen shake as decaying trauma (smooth sin noise, quadratic, self-ending)
  let trauma = 0;
  let shakeT = 0;
  let shaking = false;
  let lastTs = 0;
  function addTrauma(amount) {
    if (reduced) {
      return;
    }
    trauma = Math.min(1, trauma + amount);
    if (!shaking) {
      shaking = true;
      requestAnimationFrame(shakeTick);
    }
  }
  function shakeTick(ts) {
    const dt = lastTs ? Math.min(0.05, (ts - lastTs) / 1000) : 0.016;
    lastTs = ts;
    trauma = Math.max(0, trauma - 1.6 * dt);
    const k = trauma * trauma;
    shakeT += dt * 38;
    $$('show-stage').style.transform = trauma > 0
      ? `translate(${(9 * k * Math.sin(shakeT * 1.7)).toFixed(2)}px,${(6 * k * Math.sin(shakeT * 2.3)).toFixed(2)}px) rotate(${(0.6 * k * Math.sin(shakeT * 1.1)).toFixed(3)}deg)`
      : '';
    if (trauma > 0) {
      requestAnimationFrame(shakeTick);
    } else {
      shaking = false;
      lastTs = 0;
    }
  }

  // squash & stretch: instant deform on the event, eased back with overshoot
  function squash(el, amount, dir = 'hit') {
    if (reduced || !el.animate) {
      return;
    }
    const sx = dir === 'land' ? 1 + amount : 1 - amount * 0.6;
    const sy = dir === 'land' ? 1 - amount : 1 + amount * 0.5;
    // the boss is mirrored with a negative scale, so a push away from the fighter is a negative local x
    const sx0 = parseFloat(getComputedStyle(el).scale) || 1;
    const knock = dir === 'hit' ? `translateX(${(Math.sign(sx0) * amount * 70 / Math.abs(sx0)).toFixed(1)}px) ` : '';
    el.animate([
      { transform: `${knock}scale(${dir === 'hit' ? 1 + amount : sx},${dir === 'hit' ? 1 - amount : sy})` },
      { transform: 'scale(1,1)' },
    ], { duration: dir === 'windup' ? 140 : 260, easing: 'cubic-bezier(.34,1.56,.64,1)' });
  }

  // pooled impact debris (no create/destroy per hit)
  const debrisPool = [];
  function debrisBurst(n, x, y, colors) {
    if (reduced) {
      return;
    }
    const layer = $$('dmg-layer');
    for (let i = 0; i < n; i++) {
      let p = debrisPool.find(d => !d._busy);
      if (!p) {
        p = document.createElement('span');
        p.className = 'debris';
        layer.appendChild(p);
        debrisPool.push(p);
      }
      p._busy = true;
      p.style.display = 'block';
      p.style.background = colors[i % colors.length];
      const ang = -Math.PI * (0.15 + Math.random() * 0.7);
      const v = 60 + Math.random() * 110;
      const dx = Math.cos(ang) * v * (Math.random() < 0.5 ? -1 : 1);
      const dy = Math.sin(ang) * v;
      const piece = p;
      piece.animate([
        { transform: `translate(${x}px,${y}px) scale(1)`, opacity: 1 },
        { transform: `translate(${x + dx * 0.6}px,${y + dy * 0.7}px) scale(1)`, opacity: 1, offset: 0.45 },
        { transform: `translate(${x + dx}px,${y + dy * 0.4 + 70}px) scale(0.5)`, opacity: 0 },
      ], { duration: 520 + Math.random() * 260, easing: 'cubic-bezier(.2,.6,.4,1)' }).onfinish = () => {
        piece._busy = false;
        piece.style.display = 'none';
      };
    }
  }
  function bossPoint() {
    const st = $$('show-stage').getBoundingClientRect();
    const b = $$('boss').getBoundingClientRect();

    return { x: b.left + b.width / 2 - st.left, y: b.top + b.height * 0.45 - st.top };
  }
  function hitBoss(crit) {
    const boss = $$('boss');
    const stage = $$('show-stage');
    // hit-stop: everything freezes for a beat so the blow reads as heavy
    const frozen = [$$('show-sprite'), $$('show-effect'), boss];
    frozen.forEach(e => { e.style.animationPlayState = 'paused'; });
    boss.classList.add('flash', 'knock');
    const sparkEl = $$('hit-spark');
    sparkEl.style.opacity = 1;
    strip(sparkEl, FX.spark, 10, 26).then(() => { sparkEl.style.opacity = 0; });
    const tier = TIER[crit ? 'large' : 'medium'];
    if (crit && !reduced) {
      restart($$('flashbang'), 'go');
      stage.classList.add('punch');
    }
    const bp = bossPoint();
    debrisBurst(tier.debris, bp.x, bp.y, [getComputedStyle(stage).getPropertyValue('--acc') || '#fbbf24', '#fde68a', '#dc2626', '#fff']);
    setTimeout(() => {
      frozen.forEach(e => { e.style.animationPlayState = ''; });
      boss.classList.remove('flash', 'knock');
      stage.classList.remove('punch');
      bossStrip('hurt').then(() => bossStrip('idle'));
      addTrauma(tier.trauma);
      squash(boss, tier.squash, 'hit');
    }, reduced ? 0 : tier.stop);
    const n = Math.round((crit ? 2400 : 600) + Math.random() * (crit ? 2600 : 1400));
    const pop = document.createElement('span');
    pop.className = `dmg-pop${crit ? ' crit' : ''}`;
    pop.style.left = `${72 + Math.random() * 8}%`;
    pop.textContent = `${crit ? 'CRIT ' : ''}-${n.toLocaleString()}`;
    $$('dmg-layer').appendChild(pop);
    setTimeout(() => pop.remove(), 900);
  }
  const fighter = (anim, opts) => playAnim($$('show-sprite'), previewing, anim, opts);

  // two dust clouds kicked out from the feet on landing
  function dustPuff() {
    if (reduced) {
      return;
    }
    const stage = $$('show-stage');
    const cx = stage.getBoundingClientRect().width * 0.3;
    [-1, 1].forEach(side => {
      const d = document.createElement('span');
      d.className = 'dust';
      d.style.display = 'block';
      d.style.left = `${cx}px`;
      stage.appendChild(d);
      d.animate([
        { transform: 'translateX(-50%) scale(0.6)', opacity: 0.8 },
        { transform: `translateX(calc(-50% + ${side * 46}px)) translateY(-6px) scale(2.4, 1.6)`, opacity: 0 },
      ], { duration: 520, easing: 'cubic-bezier(.2,.7,.3,1)' }).onfinish = () => d.remove();
    });
  }
  async function summonIn(my) {
    const circle = $$('fx-circle');
    const burst = $$('fx-burst');
    restart(root.querySelector('#show-stage .floor'), 'bloom');
    const shock = $$('shock');
    shock.classList.remove('gold');
    restart(shock, 'go');
    circle.style.opacity = 1;
    strip(circle, FX.circle, 7, 14).then(() => { circle.style.opacity = 0; });
    burst.style.opacity = 1;
    strip(burst, FX.burst, 6, 12).then(() => { burst.style.opacity = 0; });
    if (BY_KEY[previewing].anims.death) {
      await fighter('death', { reverse: true });
    }
    if (my === seq) {
      squash($$('show-sprite'), TIER.small.squash + 0.1, 'land');
      addTrauma(TIER.small.trauma);
      dustPuff();
    }

    return my === seq;
  }

  // sticky move playback like the game's preview: loops loop, one-shots replay until another move is picked
  async function playMove(m) {
    const my = ++seq;
    activeMove = m.id;
    root.querySelectorAll('.move').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.id === m.id)));
    const idx = moves(previewing).findIndex(x => x.id === m.id) + 1;
    $$('move-name').innerHTML = `${m.label}<small>key ${idx}</small>`;
    $$('show-effect').style.animation = 'none';
    $$('show-effect').style.backgroundImage = 'none';
    const c = BY_KEY[previewing];
    const pressed = root.querySelector(`.move[data-id="${m.id}"]`);
    if (m.id === 'idle' || m.id === 'walk') {
      pressed?.style.setProperty('--dur', `${c.anims[m.id].frames / c.anims[m.id].rate}s`);
      fighter(m.id, { loop: true });

      return;
    }
    while (my === seq) {
      if (m.id.startsWith('attack')) {
        const a = c.anims[m.id];
        const dur = a.frames / a.rate;
        pressed?.style.setProperty('--dur', `${dur + 0.7}s`);
        const n = m.id.slice(-1);
        const effect = c.anims[`effect${n}`];
        squash($$('show-sprite'), 0.1, 'windup');
        const done = fighter(m.id);
        if (effect) {
          playAnim($$('show-effect'), previewing, `effect${n}`);
        }
        await wait(dur * 1000 * 0.62);
        if (my !== seq) {
          return;
        }
        hitBoss(n === '3');
        await done;
        if (my !== seq) {
          return;
        }
        $$('show-effect').style.backgroundImage = 'none';
        fighter('idle', { loop: true });
        await wait(700);
      } else if (m.id === 'death') {
        pressed?.style.setProperty('--dur', '2.6s');
        await fighter('death');
        if (my !== seq) {
          return;
        }
        await wait(1000);
        if (my !== seq) {
          return;
        }
        await summonIn(my);
        if (my !== seq) {
          return;
        }
        fighter('idle', { loop: true });
        await wait(500);
      } else if (m.id === 'summon') {
        pressed?.style.setProperty('--dur', '2.2s');
        await summonIn(my);
        if (my !== seq) {
          return;
        }
        fighter('idle', { loop: true });
        await wait(1200);
      } else {
        return;
      }
    }
  }

  // ---- selection ----
  function select(key) {
    previewing = key;
    const c = BY_KEY[key];
    root.querySelectorAll('.slot').forEach(el => el.setAttribute('aria-selected', String(el.dataset.key === key)));
    $$('show-stage').style.setProperty('--acc', c.accent);
    frameStage($$('show-sprite'), key);
    frameStage($$('show-effect'), key);
    ['fx-circle', 'fx-burst'].forEach(id => { $$(id).style.scale = ''; });
    root.querySelector('.showcase').style.setProperty('--acc', c.accent);
    restart($$('show-sprite'), 'swap');
    $$('show-name').textContent = pretty(key);
    const h2 = $$('show-name');
    h2.style.animation = 'none';
    void h2.offsetWidth;
    h2.style.animation = '';
    $$('show-type').textContent = TYPE_LINE[c.type];
    const mine = key === equipped;
    $$('show-state').textContent = mine ? 'Equipped' : 'Previewing';
    $$('show-state').classList.toggle('previewing', !mine);
    $$('equip-btn').disabled = mine;
    $$('equip-label').textContent = mine ? 'Equipped' : `Equip ${pretty(key)}`;
    $$('back-equipped').hidden = mine;

    const hb = $$('hotbar');
    hb.innerHTML = '';
    const ms = moves(key);
    const max = $$('move-max');
    if (max) {
      max.textContent = ms.length;
    }
    ms.forEach((m, i) => {
      const b = document.createElement('button');
      b.className = 'move';
      b.type = 'button';
      b.dataset.id = m.id;
      b.title = m.label;
      b.setAttribute('aria-pressed', 'false');
      b.innerHTML = `<span class="key">${i + 1}</span><div class="thumb"><span></span></div><span class="label">${m.label}</span>`;
      paint(b.querySelector('.thumb span'), key, m.anim || m.id, { reverse: m.reverse });
      frameIn(b.querySelector('.thumb span'), key, { maxW: 44, maxH: 32, cyPct: 52 });
      b.addEventListener('click', () => playMove(m));
      hb.appendChild(b);
    });
    paintCharge($$('show-stage'), key);
    paintAlso(key);
    const my = ++seq;
    summonIn(my).then(ok => { if (ok) { playMove(ms[0]); } });
  }

  // hover a slot to peek at that fighter on the stage; leaving the roster puts back the one you picked
  let peekTimer = null;
  function peek(key) {
    const stage = $$('show-stage');
    ++seq;
    stage.classList.add('peeking');
    stage.style.setProperty('--acc', BY_KEY[key].accent);
    frameStage($$('show-sprite'), key);
    frameStage($$('show-effect'), key);
    playAnim($$('show-sprite'), key, 'idle', { loop: true });
    $$('show-effect').style.backgroundImage = 'none';
    paintCharge(stage, key);
  }
  function unpeek() {
    const stage = $$('show-stage');
    if (!stage.classList.contains('peeking')) {
      return;
    }
    stage.classList.remove('peeking');
    stage.style.setProperty('--acc', BY_KEY[previewing].accent);
    frameStage($$('show-sprite'), previewing);
    frameStage($$('show-effect'), previewing);
    paintCharge(stage, previewing);
    playMove(moves(previewing).find(m => m.id === activeMove) || moves(previewing)[0]);
  }
  on(grid, 'pointerover', e => {
    const sl = e.target.closest('.slot');
    if (!sl || sl.dataset.key === previewing) {
      return;
    }
    clearTimeout(peekTimer);
    peekTimer = setTimeout(() => peek(sl.dataset.key), 120); // a short delay so sweeping across the grid doesn't strobe
  });
  on(grid, 'pointerleave', () => {
    clearTimeout(peekTimer);
    unpeek();
  });
  on(grid, 'click', () => {
    clearTimeout(peekTimer);
    $$('show-stage').classList.remove('peeking');
  }, true);

  // filter by fighting style
  root.querySelectorAll('.chip').forEach(ch => on(ch, 'click', () => {
    root.querySelectorAll('.chip').forEach(x => x.setAttribute('aria-pressed', String(x === ch)));
    let n = 0;
    root.querySelectorAll('.slot').forEach(sl => {
      const hide = Boolean(ch.dataset.type) && sl.dataset.type !== ch.dataset.type;
      sl.classList.toggle('filtered-out', hide);
      if (!hide) {
        n++;
      }
    });
    $$('roster-count').textContent = `${n}${n === 1 ? ' fighter' : ' fighters'}`;
  }));

  // keyboard: arrows walk the roster grid, 1-9 play a move, ←/→ walk the hotbar
  on(grid, 'keydown', e => {
    const slots = [...grid.children];
    const i = slots.indexOf(document.activeElement);
    const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 4, ArrowUp: -4 }[e.key];
    if (i < 0 || !step) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    const n = slots[Math.max(0, Math.min(slots.length - 1, i + step))];
    n.focus();
    select(n.dataset.key);
  });
  on(document, 'keydown', e => {
    if (!root.closest('.tab-panel')?.classList.contains('is-active') || e.target.tagName === 'INPUT') {
      return;
    }
    const n = parseInt(e.key, 10);
    const ms = [...root.querySelectorAll('.move')];
    if (n >= 1 && n <= ms.length) {
      ms[n - 1].click();
    }
  });
  on($$('hotbar'), 'keydown', e => {
    if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') {
      return;
    }
    const ms = [...root.querySelectorAll('.move')];
    const i = ms.indexOf(document.activeElement);
    if (i < 0) {
      return;
    }
    e.preventDefault();
    e.stopPropagation();
    const n = ms[(i + (e.key === 'ArrowRight' ? 1 : ms.length - 1)) % ms.length];
    n.focus();
    n.click();
  });

  // ---- equip ----
  function markYou(key) {
    root.querySelectorAll('.slot .tag').forEach(t => t.remove());
    root.querySelector(`.slot[data-key="${key}"]`)?.insertAdjacentHTML('beforeend', '<span class="tag">YOU</span>');
  }
  function toast(html, withAction) {
    const t = document.getElementById('toast');
    if (!t) {
      return null;
    }
    clearTimeout(t._h);
    t.className = `toast show${withAction ? ' with-action' : ''}`;
    t.innerHTML = html;
    t._h = setTimeout(() => { t.className = 'toast'; }, withAction ? 6000 : 1800);

    return t;
  }
  let persistTimer = null;
  on($$('equip-btn'), 'click', () => {
    beforeEquip = equipped;
    equipped = previewing;
    markYou(equipped);
    select(equipped);
    restart($$('equip-btn'), 'flash');
    const shock = $$('shock');
    shock.classList.add('gold');
    restart(shock, 'go');
    setTimeout(() => restart(shock, 'go'), 260);
    addTrauma(TIER.large.trauma * 0.7);
    squash($$('show-sprite'), TIER.large.squash, 'land');
    const banner = $$('banner');
    banner.textContent = `Now fighting as ${pretty(equipped)}`;
    restart(banner, 'show');
    const first = moves(equipped).find(m => m.id.startsWith('attack'));
    if (first) {
      setTimeout(() => playMove(first), 700);
    }
    const previous = beforeEquip;
    const chosen = equipped;
    clearTimeout(persistTimer);
    let persisted = false;
    // persisted once the camera has pushed in, so the field's swap plays in view
    persistTimer = setTimeout(() => {
      persisted = true;
      equip(chosen);
    }, EQUIP_PERSIST_DELAY_MS);
    const t = toast(`<span>Now fighting as ${pretty(chosen)}.</span><button class="undo" type="button">Undo</button>`, true);
    t?.querySelector('.undo')?.addEventListener('click', () => {
      if (persisted) {
        equip(previous);
      } else {
        clearTimeout(persistTimer);
      }
      equipped = previous;
      markYou(equipped);
      select(equipped);
      toast(`Back to ${pretty(equipped)}.`, false);
    });
    // let the in-sheet shockwave + banner land first, then the sheet folds onto the real battlefield
    setTimeout(() => dispatch('fighter-equipped'), EQUIP_FOLD_DELAY_MS);
  });
  on($$('back-equipped'), 'click', () => {
    select(equipped);
    root.querySelector(`.slot[data-key="${equipped}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });

  // ---- one tooltip for every icon-only button (roster slots and moves) ----
  const TYPE_NAMES = { SLASH: 'Slash', BLADE: 'Blade', SHURIKEN: 'Shuriken', ARROW: 'Arrow', BLAST: 'Blast' };
  const tipShow = e => {
    const el = e.target.closest?.('.slot, .move');
    const tip = document.getElementById('tipbox');
    if (!el || !tip || !root.contains(el)) {
      return;
    }
    const isSlot = el.classList.contains('slot');
    const name = isSlot ? pretty(el.dataset.key) : el.title;
    const sub = isSlot ? TYPE_NAMES[el.dataset.type] + (el.dataset.key === equipped ? ' · yours' : '') + (el.dataset.mates ? ` · ${el.dataset.mates}` : '') : `key ${[...root.querySelectorAll('.move')].indexOf(el) + 1}`;
    tip.innerHTML = `${escapeHtml(name)}<small>${escapeHtml(sub)}</small>`;
    const r = el.getBoundingClientRect();
    tip.style.left = `${Math.round(r.left + r.width / 2)}px`;
    tip.style.top = `${Math.round(r.top - 8)}px`;
    tip.style.translate = '-50% -100%';
    tip.classList.add('show');
  };
  const tipHide = e => {
    if (e.target.closest?.('.slot, .move')) {
      document.getElementById('tipbox')?.classList.remove('show');
    }
  };
  ['pointerover', 'focusin'].forEach(ev => on(root, ev, tipShow));
  ['pointerout', 'focusout'].forEach(ev => on(root, ev, tipHide));
  // a click re-renders the slot (no pointerout follows), and the tooltip host
  // outlives the sheet: hide it on press and on teardown
  on(root, 'pointerdown', () => document.getElementById('tipbox')?.classList.remove('show'));
  cleanup.push(() => document.getElementById('tipbox')?.classList.remove('show'));

  // re-frame the fighter whenever the stage's real size changes (tab opened, window resized)
  if (typeof ResizeObserver === 'function') {
    const ro = new ResizeObserver(() => {
      const st = $$('show-stage');
      if (!st.clientWidth || !st.clientHeight) {
        return;
      }
      frameStage($$('show-sprite'), previewing);
      frameStage($$('show-effect'), previewing);
    });
    ro.observe($$('show-stage'));
    cleanup.push(() => ro.disconnect());
  }

  // opening the Character tab replays the roster pop-in and summons the picked fighter
  if (typeof MutationObserver === 'function') {
    const panel = root.closest('.tab-panel');
    if (panel) {
      let wasActive = panel.classList.contains('is-active');
      const mo = new MutationObserver(() => {
        const active = panel.classList.contains('is-active');
        if (active && !wasActive) {
          restart(grid, 'is-popping');
          select(previewing);
          setTimeout(() => root.querySelector('.slot[aria-selected="true"]')?.focus({ preventScroll: true }), 0);
        }
        wasActive = active;
      });
      mo.observe(panel, { attributes: true, attributeFilter: ['class'] });
      cleanup.push(() => mo.disconnect());
    }
  }

  cleanup.push(sparkField($$('show-stage'), (w, h) => sparkPoint({
    width: w,
    height: h,
    cxRatio: 0.30,
    floor: FLOOR,
    lift: parseFloat(getComputedStyle($$('show-stage')).getPropertyValue('--lift')) || 0,
    box: BOX[previewing],
    heel: heelOf(previewing),
    scale: parseFloat($$('show-sprite').style.scale) || 3,
  }), () => parseFloat($$('show-sprite').style.scale)));

  $$('stage-avatar')?.setAttribute('title', handle);
  select(equipped);
  cleanup.push(() => { seq++; clearTimeout(peekTimer); });

  return cleanup;
}

/**
 * Escapes text for interpolation into innerHTML.
 *
 * @param {string} text Raw text.
 * @return {string}
 */
function escapeHtml(text) {
  return String(text).replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' })[ch]);
}
