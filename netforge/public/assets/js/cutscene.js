// Cutscenes: the story's opening (DataTech raids your dad's datacentre) and its ending (you buy
// DataTech and get him back). A small stage: a pixel-art canvas over the whole screen with
// characters that walk, a dropship that flies, shakes, flashes, fades, captions and the usual
// dialogue box. Skip (or Esc) jumps past the whole scene at any point.
import * as art from './game/art.js';
import * as story from './game/story-art.js';
import { TILE, LOCATIONS, LOC_CODE, setLocation } from './game/layout.js';
import { playDialogue } from './dialogue.js';
import { loadStory } from './story.js';

const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const W = 36 * TILE, H = 20 * TILE; // both scenes are datacentre-sized

/* ======================= the stage ======================= */

function createStage(storyData, player, background) {
  const el = document.createElement('div');
  el.className = 'cutscene';
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-label', 'Cutscene');
  el.innerHTML = `<canvas width="${W}" height="${H}" aria-hidden="true"></canvas>
    <p class="cs-caption" aria-live="polite"></p>
    <button type="button" class="cs-skip">Skip scene <kbd>Esc</kbd></button>`;
  document.body.append(el);
  const cv = el.querySelector('canvas');
  const c = cv.getContext('2d');
  c.imageSmoothingEnabled = false;

  const frames = new Map(); // palette:pose -> canvas
  const frame = (pal, pose) => {
    const k = `${pal}:${pose}`;
    if (!frames.has(k)) {
      const f = document.createElement('canvas');
      f.width = art.PLAYER_SIZE.w; f.height = art.PLAYER_SIZE.h;
      art.playerFrame(f.getContext('2d'), pal, pose);
      frames.set(k, f);
    }
    return frames.get(k);
  };

  const stage = {
    el, skipped: false, actors: [], particles: [], fadeA: 1, flashA: 0, shakeUntil: 0, shakeMag: 0, breach: null, t0: performance.now(),
    person(pal, tx, ty, flip = false, carry = null) {
      const a = { kind: 'person', pal, x: tx * TILE + 8, y: ty * TILE + 15, flip, walking: false, visible: true, carry };
      stage.actors.push(a);
      return a;
    },
    thing(draw, x, y, extra = {}) {
      const a = { kind: 'thing', draw, x, y, visible: true, ...extra };
      stage.actors.push(a);
      return a;
    },
    wait(ms) { return stage.skipped ? Promise.resolve() : new Promise((r) => { const t = setTimeout(r, ms); stage.timers.push([t, r]); }); },
    timers: [],
    /** Walks a person to a tile, at px per second. */
    walk(a, tx, ty, speed = 48) {
      const to = { x: tx * TILE + 8, y: ty * TILE + 15 };
      return stage.move(a, to.x, to.y, (Math.hypot(to.x - a.x, to.y - a.y) / speed) * 1000, true);
    },
    /** Walks through tiles one after another, so nobody cuts through a rack. */
    async path(a, tiles, speed = 48) {
      for (const [tx, ty] of tiles) await stage.walk(a, tx, ty, speed);
    },
    /** Moves an actor to a point (map pixels) over ms, easing out for things that fly. */
    move(a, x, y, ms, walking = false, ease = (k) => k) {
      if (stage.skipped || reduceMotion || ms <= 0) { a.x = x; a.y = y; return Promise.resolve(); }
      const from = { x: a.x, y: a.y }, start = performance.now();
      if (x !== a.x) a.flip = x < a.x;
      a.walking = walking;
      return new Promise((resolve) => {
        a.tween = (now) => {
          const k = Math.min(1, (now - start) / ms), e = ease(k);
          a.x = from.x + (x - from.x) * e; a.y = from.y + (y - from.y) * e;
          if (k >= 1) { a.tween = null; a.walking = false; resolve(); }
        };
        a.finish = () => { a.x = x; a.y = y; a.tween = null; a.walking = false; resolve(); };
      });
    },
    async say(lines) {
      if (stage.skipped) return 'skipped';
      const r = await playDialogue(storyData, lines, { player, skipLabel: 'Skip scene', host: el });
      if (r === 'skipped') stage.skip();
      return r;
    },
    caption(text) { el.querySelector('.cs-caption').textContent = text || ''; },
    shake(ms, mag = 3) { stage.shakeUntil = performance.now() + ms; stage.shakeMag = mag; },
    flash() { stage.flashA = 1; },
    fade(to, ms) {
      if (stage.skipped || reduceMotion) { stage.fadeA = to; return Promise.resolve(); }
      const from = stage.fadeA, start = performance.now();
      return new Promise((resolve) => {
        stage.fading = (now) => {
          const k = Math.min(1, (now - start) / ms);
          stage.fadeA = from + (to - from) * k;
          if (k >= 1) { stage.fading = null; stage.fadeDone = null; resolve(); }
        };
        stage.fadeDone = () => { stage.fadeA = to; stage.fading = null; stage.fadeDone = null; resolve(); };
      });
    },
    debris(x, y, n = 40) {
      for (let i = 0; i < n; i++) {
        stage.particles.push({ x, y, vx: -40 - Math.random() * 120, vy: -60 + Math.random() * 90, life: 1 + Math.random(),
          col: ['#3a4d6e', '#5a6b84', '#ffb43d', '#2c3b56'][i % 4], s: 1 + (i % 3 === 0 ? 1 : 0) });
      }
    },
    hearts(x, y) {
      for (let i = 0; i < 6; i++) stage.particles.push({ x: x + (Math.random() - 0.5) * 20, y, vx: (Math.random() - 0.5) * 10, vy: -14 - Math.random() * 10, life: 2.5, heart: true, delay: i * 0.25 });
    },
    skip() {
      if (stage.skipped) return;
      stage.skipped = true;
      for (const a of stage.actors) a.finish?.();
      for (const [t, r] of stage.timers) { clearTimeout(t); r(); }
      stage.fadeDone?.(); // finish a fade in progress, so nothing waits on it
      el.querySelector('.dlg .dlg-skip')?.click();
    },
    close() {
      cancelAnimationFrame(stage.raf);
      removeEventListener('keydown', onKey, true);
      el.classList.add('out');
      setTimeout(() => el.remove(), reduceMotion ? 0 : 400);
    },
  };

  function onKey(e) {
    if (e.key === 'Escape' && !el.querySelector('.dlg')) { e.preventDefault(); e.stopPropagation(); stage.skip(); }
  }
  addEventListener('keydown', onKey, true);
  el.querySelector('.cs-skip').addEventListener('click', () => stage.skip());

  let last = performance.now();
  const loop = (now) => {
    const dt = Math.min(0.05, (now - last) / 1000), t = (now - stage.t0) / 1000;
    last = now;
    for (const a of stage.actors) a.tween?.(now);
    stage.fading?.(now);
    c.save();
    if (now < stage.shakeUntil && !reduceMotion) c.translate(Math.round((Math.random() - 0.5) * stage.shakeMag * 2), Math.round((Math.random() - 0.5) * stage.shakeMag * 2));
    background(c, t);
    if (stage.breach) story.wallBreach(c, ...stage.breach);
    // people and things, back to front
    for (const a of [...stage.actors].filter((x) => x.visible).sort((p, q) => p.y - q.y)) {
      if (a.kind === 'person') {
        const step = a.walking ? ['walk1', 'walk0', 'walk2', 'walk0'][Math.floor(t * 9) % 4] : (Math.floor(t * 2) % 2 ? 'idle1' : 'idle0');
        const f = frame(a.pal, reduceMotion ? 'idle0' : step);
        c.save();
        c.translate(Math.round(a.x), Math.round(a.y));
        if (a.flip) c.scale(-1, 1);
        c.drawImage(f, -6, -20);
        if (a.carry === 'laptop') { // an open laptop held in front
          c.fillStyle = '#05070d'; c.fillRect(1, -11, 8, 6);
          c.fillStyle = '#0a2a33'; c.fillRect(2, -10, 6, 3);
          c.fillStyle = '#2be3ff'; c.fillRect(2, -10, 3, 1);
          c.fillStyle = '#3a4660'; c.fillRect(1, -6, 9, 1);
        }
        c.restore();
      } else a.draw(c, Math.round(a.x), Math.round(a.y), t, a);
    }
    // debris and hearts
    for (const p of stage.particles) {
      if (p.delay > 0) { p.delay -= dt; continue; }
      p.life -= dt; p.x += p.vx * dt; p.y += p.vy * dt;
      if (!p.heart) p.vy += 160 * dt;
      if (p.life > 0) p.heart ? story.heart(c, Math.round(p.x), Math.round(p.y)) : (c.fillStyle = p.col, c.fillRect(Math.round(p.x), Math.round(p.y), p.s, p.s));
    }
    stage.particles = stage.particles.filter((p) => p.life > 0);
    c.restore();
    if (stage.flashA > 0) { c.fillStyle = `rgba(255,240,220,${stage.flashA})`; c.fillRect(0, 0, W, H); stage.flashA = Math.max(0, stage.flashA - dt * 2.5); }
    if (stage.fadeA > 0) { c.fillStyle = `rgba(0,0,0,${stage.fadeA})`; c.fillRect(0, 0, W, H); }
    stage.raf = requestAnimationFrame(loop);
  };
  stage.raf = requestAnimationFrame(loop);
  return stage;
}

/** The datacentre at night, busy with work: racks humming, desks with PCs, cable trays and decor. Drawn once. */
const RACKS = LOCATIONS.datacentre.slots.filter((s) => s.kind === 'rack' && (s.x * 7 + s.y) % 5 !== 0); // most slots full, a few gaps
function datacentreBackground() {
  const bg = document.createElement('canvas');
  bg.width = W; bg.height = H;
  const c = bg.getContext('2d');
  const R = (x, y, w, h, col) => { c.fillStyle = col; c.fillRect(x, y, w, h); };
  const was = LOC_CODE;
  setLocation('datacentre'); // the room art reads the size of the place being drawn
  // Dad's datacentre is one big open hall (not the player's, with its rooms): no interior walls,
  // the cooling units down the right and the desks along the bottom
  const hall = {
    ...LOCATIONS.datacentre, rooms: undefined, walls: undefined,
    furniture: [[33, 3], [33, 8], [33, 13]].map(([x, y]) => ({ type: 'cooling', x, y }))
      .concat([[1, 16], [8, 16]].map(([x, y]) => ({ type: 'desk', x, y }))),
  };
  art.drawRoom(c, 'datacentre', hall);
  art.drawFurniture(c, 'desk', 15, 16);                    // a third desk for the night shift
  for (const [x, y] of [[0, 12], [19, 17], [31, 18]]) art.drawFurniture(c, 'plant', x, y);
  setLocation(was);

  // overhead cable trays above each rack row, with cables in them
  for (const row of [3, 8, 13]) {
    const y = row * TILE - 3;
    R(3 * TILE - 2, y, 16 * TILE + 4, 3, '#2a3850'); R(3 * TILE - 2, y, 16 * TILE + 4, 1, '#3a4d6e');
    R(3 * TILE, y + 1, 16 * TILE, 1, '#1590a6'); R(3 * TILE + 6, y + 2, 16 * TILE - 12, 1, '#a8661d');
  }
  // the racks themselves
  const rack = art.SPRITES.rack;
  for (const s of RACKS) { c.save(); c.translate(s.x * TILE + rack.off[0], s.y * TILE + rack.off[1]); rack.draw(c); c.restore(); }
  // fibre panel on the wall, and the runs from it to the rack rows
  const panel = art.SPRITES.fibrepanel;
  c.save(); c.translate(18 * TILE + panel.off[0], 1 * TILE + panel.off[1]); panel.draw(c); c.restore();
  for (let y = 2 * TILE + 8; y < 14 * TILE; y += 4) R(19 * TILE + 4, y, 1, 2, '#d9a03a');
  for (const row of [3, 8, 13]) R(18 * TILE + 14, row * TILE - 2, 7, 1, '#d9a03a');
  // PCs on every desk, monitors lit
  const tower = art.SPRITES.tower;
  for (const [dx, dy] of [[1, 16], [8, 16], [15, 16]]) {
    c.save(); c.translate((dx + 3) * TILE + tower.off[0], dy * TILE + tower.off[1]); tower.draw(c); c.restore();
    art.monitorOn(c, dx, dy);
  }
  // the NetForge sign on the wall, a clock and a fire extinguisher
  R(132, 8, 52, 14, '#0a1622'); R(132, 8, 52, 1, '#1d2a3c');
  c.fillStyle = '#2be3ff'; c.font = 'bold 8px monospace'; c.textBaseline = 'top'; c.fillText('NETFORGE', 136, 11);
  R(310, 9, 12, 12, '#1d2a3c'); R(311, 10, 10, 10, '#0a1622'); R(316, 11, 1, 5, '#e8eef4'); R(316, 15, 3, 1, '#e8eef4');
  R(4, 22, 4, 9, '#b8232f'); R(5, 21, 2, 2, '#3a4d6e');
  return (ctx, t) => {
    ctx.drawImage(bg, 0, 0);
    for (const s of RACKS) { // rack lights blinking
      for (let i = 0; i < 3; i++) if (Math.sin(t * (2 + i) + s.x * 3 + i) > 0.2) { ctx.fillStyle = i ? '#7cff6b' : '#2be3ff'; ctx.fillRect(s.x * TILE + 3 + i * 4, s.y * TILE + 13 + i * 3, 1, 1); }
    }
  };
}

const easeOut = (k) => 1 - (1 - k) ** 3;

/* ======================= the opening: the raid ======================= */

export async function playIntro(store) {
  const data = await loadStory();
  const player = store.state.user?.name || 'you';
  store.set({ cutsceneOpen: true, uiFocus: true });
  const s = createStage(data, player, datacentreBackground());
  try {
    // Dad and Ada in the open floor right of the racks; the night shift at work in the aisles and at the desks
    const dad = s.person('dad', 22, 11);
    const ada = s.person('ada', 24, 11, true);
    const staff = [
      s.person('staff1', 6, 6, false, 'laptop'), s.person('staff2', 13, 11, true, 'laptop'),
      s.person('staff3', 16, 6, true, 'laptop'), s.person('staff1', 9, 19, false),
    ];
    s.caption('NetForge HQ datacentre. 2:13 a.m.');
    await s.fade(0, 1400);
    await s.say([
      ['dad', 'Look at that, Ada. The new fibre ring is live. Best numbers we’ve ever had.'],
      ['teacher', 'DataTech won’t like it. Lucas has called four times this week.'],
      ['dad', 'Let him call. Our network is faster, and our clients know it.'],
    ]);
    s.caption('');
    s.shake(1400, 1);
    staff.forEach((p) => { p.flip = false; }); // everyone turns to the noise
    await s.wait(900);
    await s.say([['dad', '…What was that?']]);

    // the wall goes, and the dropship comes in over the open floor
    s.flash(); s.shake(900, 5);
    s.breach = [486, 26, 90, 112];
    s.debris(520, 80, 60);
    await s.wait(500);
    const ship = s.thing((c, x, y, t, a) => story.dropship(c, x, y + Math.round(Math.sin(t * 2) * 1.5), t, a.door), 640, 40, { door: 0 });
    await s.move(ship, 430, 40, 1600, false, easeOut);
    s.shake(400, 2);
    for (let k = 0; k <= 10; k++) { ship.door = k / 10; await s.wait(50); }

    // the Reapers drop from the door and surround Dad and Ada in the middle of the room
    const door = { tx: 29, ty: 6 };
    const spots = [[20, 9], [24, 8], [27, 10], [19, 13], [26, 13], [22, 14]];
    const reapers = [];
    for (const [tx, ty] of spots) {
      const r = s.person('reaper', door.tx, door.ty, true);
      reapers.push(r);
      s.walk(r, tx, ty, 90);
      await s.wait(220);
    }
    await s.wait(700);
    const lucas = s.person('lucas', door.tx, door.ty, true);
    await s.walk(lucas, 26, 9, 40);
    await s.say([
      ['reaper', 'DataTech security! Everybody on the floor!'],
      ['lucas', 'Evening, {dad}. Sorry about the wall. My people get excited.'],
      ['dad', 'Lucas. You sent your Reapers into my building?'],
      ['lucas', 'Hostile takeovers get messy, {dad}. That’s why I keep Reapers on the payroll. They don’t ask questions.'],
      ['lucas', 'Your servers, your clients, your network. As of tonight, it all belongs to DataTech. This is the end of NetForge.'],
    ]);

    // everyone runs, along the aisles and out to the left; Dad sends Ada away
    staff.forEach((p) => s.walk(p, -2, Math.round((p.y - 15) / TILE), 110));
    await s.wait(500);
    await s.say([
      ['dad', 'Ada. Go. Now.'],
      ['teacher', 'I’m not leaving you here!'],
      ['dad', 'Find my kid. Teach them everything we know. Go!'],
    ]);
    await s.path(ada, [[24, 12], [-2, 12]], 120); // down to the aisle, then out
    await s.say([['lucas', 'Let her run. Take him.']]);
    await Promise.all([s.walk(reapers[0], 21, 11, 60), s.walk(reapers[3], 23, 12, 60)]);
    await s.wait(300);
    // led away into the dropship
    for (const p of [dad, reapers[0], reapers[3]]) s.path(p, [[p === dad ? 26 : 27, 11], [door.tx, door.ty]], 50);
    await s.wait(3200);
    dad.visible = false; reapers[0].visible = false; reapers[3].visible = false;
    for (const r of [reapers[1], reapers[2], reapers[4], reapers[5]]) s.walk(r, door.tx, door.ty, 80);
    await s.walk(lucas, door.tx, door.ty, 40);
    reapers.forEach((r) => { r.visible = false; }); lucas.visible = false;
    for (let k = 10; k >= 0; k--) { ship.door = k / 10; await s.wait(40); }
    await s.move(ship, 680, 20, 1500, false, (k) => k * k);
    await s.fade(1, 1200);
    s.caption('Three days later. Your garage.');
    await s.wait(2200);
  } finally {
    s.close();
    store.set({ cutsceneOpen: false, uiFocus: !!store.state.learnOpen });
  }
}

/* ======================= the ending: getting Dad back ======================= */

/** Plays the ending. Resolves 'rewind' if the student wants to go back to before buying DataTech, else 'stay'. */
export async function playEnding(store) {
  const data = await loadStory();
  const player = store.state.user?.name || 'you';
  store.set({ cutsceneOpen: true, uiFocus: true });
  const s = createStage(data, player, (c, t) => story.prisonRoom(c, W, H, t));
  try {
    const cell = { x: 240, y: 44, w: 96, h: 50 };
    const dad = s.person('dad', 18, 5);
    dad.flip = true;
    const bars = s.thing((c, x, y, t, a) => story.cellBars(c, cell.x, cell.y, cell.w, cell.h, a.open), 0, cell.y + cell.h, { open: 0 });
    bars.y = cell.y + cell.h; // in front of Dad
    const me = s.person(store.state.user?.avatar || 'cyan', -1, 14);
    const ada = s.person('ada', -2, 15);
    s.caption('DataTech HQ. Sublevel 3.');
    await s.fade(0, 1400);
    await Promise.all([s.walk(me, 16, 9, 50), s.walk(ada, 13, 10, 46)]);
    s.caption('');
    await s.say([['teacher', 'We own this building now, {player}. Every door here answers to you.']]);
    for (let k = 0; k <= 20; k++) { bars.open = k / 20; await s.wait(45); }
    await s.walk(dad, 18, 8, 30);
    await s.say([
      ['dad', '…{player}? Is that really you?'],
      ['teacher', 'Your kid learned everything we knew. Cables, routing, security. Then bought DataTech outright.'],
      ['dad', 'Every protocol, every cable. I’m so proud of you.'],
    ]);
    // the hug
    me.flip = false;
    await s.walk(dad, 17, 9, 30);
    dad.flip = true;
    s.hearts(17 * TILE, 9 * TILE - 6);
    await s.wait(2600);
    await s.say([['dad', 'Let’s go home. Ada, I think NetForge has a new CEO.']]);
    await s.fade(1, 1400);
    s.caption('');
  } finally {
    s.close();
  }
  // the end, and a way back
  const choice = await endCard(player);
  store.set({ cutsceneOpen: false, uiFocus: !!store.state.learnOpen });
  return choice;
}

/** The end card: go back to before buying DataTech, or stay at the ending. Resolves 'rewind' or 'stay'. */
export function endCard(player) {
  return new Promise((resolve) => {
    const el = document.createElement('div');
    el.className = 'end-card';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-label', 'The end');
    el.innerHTML = `<div><h2>The end</h2>
      <p>You saved your dad and took down DataTech. Well done, ${player.replace(/[<>&"]/g, '')}.</p>
      <p class="muted">Thanks for playing NetForge. Want to go back to just before you bought DataTech and keep playing? You get the 999B back.</p>
      <div class="end-actions"><button type="button" class="btn primary" data-end="rewind">Go back and keep playing</button>
      <button type="button" class="btn" data-end="stay">Stay at the ending</button></div></div>`;
    document.body.append(el);
    el.tabIndex = -1;
    el.focus(); // not a button: an Enter still held from the cutscene mustn't choose for them
    el.addEventListener('click', (e) => {
      const b = e.target.closest('[data-end]');
      if (!b) return;
      el.remove();
      resolve(b.dataset.end);
    });
  });
}
