// Pixel art, drawn in code at 1 logical pixel = 1 canvas pixel.
// Every sprite is a function so the map, inventory and shop share one look.
import { TILE, COLS, ROWS } from './layout.js';

export const C = {
  k: '#05070d', cyan: '#2be3ff', amber: '#ffb43d', lime: '#7cff6b', magenta: '#ff3d9a', violet: '#9c8cff',
  metal1: '#1b2436', metal2: '#2c3b56', metal3: '#3a4d6e', deep: '#0a0f19',
};
export const CABLE_COLORS = { copper: C.cyan, crossover: C.cyan, fiber: C.amber, console: C.violet };

const R = (c, x, y, w, h, col) => { c.fillStyle = col; c.fillRect(x, y, w, h); };

/** Pixel-stepped rounded rectangle. */
function rrect(c, x, y, w, h, r, col) {
  for (let j = 0; j < h; j++) {
    const d = j < r ? r - j : j >= h - r ? j - (h - r - 1) : 0;
    const inset = d ? Math.round(r - Math.sqrt(Math.max(0, r * r - d * d))) : 0;
    R(c, x + inset, y + j, w - inset * 2, 1, col);
  }
}

/** Trapezoid: rows widen (slope > 0) or narrow (slope < 0) by up to |slope| px a side. */
function trap(c, x, y, w, h, slope, col) {
  for (let j = 0; j < h; j++) {
    const t = slope > 0 ? 1 - j / (h - 1) : j / (h - 1);
    const inset = Math.round(Math.abs(slope) * t);
    R(c, x + inset, y + j, w - inset * 2, 1, col);
  }
}
export const hash = (x, y) => {
  let h = x * 374761393 + y * 668265263;
  h = (h ^ (h >> 13)) * 1274126177;
  return ((h ^ (h >> 16)) >>> 0) / 4294967295;
};

/* ======================= devices ======================= */

export function tower(c, x, y, led = C.cyan) {
  R(c, x + 1, y + 14, 8, 2, 'rgba(0,0,0,.45)');
  R(c, x, y, 8, 15, C.k); R(c, x + 1, y + 1, 6, 13, C.metal1); R(c, x + 1, y + 1, 6, 3, C.metal2);
  for (const dy of [6, 8, 10]) R(c, x + 2, y + dy, 4, 1, '#121a29');
  R(c, x + 5, y + 5, 1, 1, led);
}

/** Rack body without LEDs (LEDs are animated by the scene). */
export function rack(c, x, y, accent = '#1590a6') {
  R(c, x, y + 31, 16, 2, 'rgba(0,0,0,.5)');
  R(c, x, y, 16, 31, C.k); R(c, x + 1, y + 1, 14, 10, '#1c2638'); R(c, x + 1, y + 1, 14, 1, '#33446a');
  for (let i = 0; i < 4; i++) R(c, x + 3, y + 3 + i * 2, 10, 1, '#111a28');
  R(c, x + 1, y + 11, 14, 19, C.deep); R(c, x + 1, y + 11, 1, 19, '#2a3850'); R(c, x + 14, y + 11, 1, 19, '#2a3850');
  for (let u = 0; u < 5; u++) {
    R(c, x + 2, y + 12 + u * 3, 12, 2, '#131c2c');
    for (let j = 0; j < 4; j++) R(c, x + 3 + j * 2, y + 12 + u * 3, 1, 1, '#1a2436');
    R(c, x + 12, y + 13 + u * 3, 1, 1, C.metal2);
  }
  if (accent) R(c, x + 4, y + 5, 8, 2, accent);
}
const RACK_LEDS = [];
for (let u = 0; u < 5; u++) for (let j = 0; j < 4; j++) RACK_LEDS.push([3 + j * 2, 12 + u * 3]);

/** The starter laptop: cracked screen, still boots. */
export function laptop(c, x, y) {
  R(c, x + 1, y + 10, 13, 2, 'rgba(0,0,0,.45)');
  // lid
  R(c, x, y, 14, 7, C.k); R(c, x + 1, y + 1, 12, 5, '#071c24');
  R(c, x + 2, y + 2, 5, 1, C.cyan); R(c, x + 2, y + 4, 7, 1, '#1590a6');
  // crack across the screen
  for (const [cx, cy] of [[8, 1], [9, 2], [9, 3], [10, 3], [11, 4], [8, 4], [7, 5]]) R(c, x + cx, y + cy, 1, 1, '#9fb4c7');
  R(c, x + 11, y + 1, 2, 1, C.magenta); // sticker
  // base
  R(c, x, y + 7, 14, 4, C.k); R(c, x + 1, y + 7, 12, 3, '#2a3446');
  for (let i = 0; i < 5; i++) R(c, x + 2 + i * 2, y + 8, 1, 1, '#465672');
  R(c, x + 5, y + 9, 4, 1, '#3a4660');
}

/** Consumer Wi-Fi router with two antennas. */
export function homeRouter(c, x, y) {
  R(c, x + 1, y + 9, 13, 2, 'rgba(0,0,0,.45)');
  R(c, x + 2, y, 1, 5, C.k); R(c, x + 11, y, 1, 5, C.k);
  R(c, x + 2, y, 1, 1, '#465672'); R(c, x + 11, y, 1, 1, '#465672');
  R(c, x, y + 4, 14, 6, C.k); R(c, x + 1, y + 5, 12, 4, '#b8c4d2'); R(c, x + 1, y + 5, 12, 1, '#e3eaf1');
  for (let i = 0; i < 4; i++) R(c, x + 3 + i * 2, y + 7, 1, 1, '#5b6b80');
}

/** ISP fibre termination box on the wall, with the fibre dropping from the ceiling. */
export function ispBox(c, x, y) {
  R(c, x + 5, y, 1, 12, '#d9a03a'); R(c, x + 6, y, 1, 12, 'rgba(0,0,0,.4)');
  R(c, x, y + 11, 12, 14, C.k); R(c, x + 1, y + 12, 10, 12, '#cfd7e0'); R(c, x + 1, y + 12, 10, 1, '#eef3f7');
  R(c, x + 2, y + 15, 8, 1, '#9aa7b6'); R(c, x + 2, y + 17, 8, 1, '#9aa7b6');
  R(c, x + 3, y + 21, 6, 2, '#6f7d8f');
}

/** Firewall appliance: a squat box with a hazard stripe and status lights. */
export function firewall(c, x, y) {
  R(c, x + 1, y + 8, 13, 2, 'rgba(0,0,0,.45)');
  R(c, x, y, 14, 9, C.k); R(c, x + 1, y + 1, 12, 7, '#3a1626'); R(c, x + 1, y + 1, 12, 1, '#5c2238');
  for (let i = 0; i < 6; i++) R(c, x + 1 + i * 2, y + 2, 1, 1, i % 2 ? C.magenta : '#ff9bc7');
  R(c, x + 2, y + 6, 10, 1, '#1d0b13');
}

/** Datacentre fibre entry panel on the wall. */
export function fibrePanel(c, x, y) {
  R(c, x + 4, y, 1, 12, '#d9a03a'); R(c, x + 7, y, 1, 12, '#d9a03a'); R(c, x + 8, y, 1, 12, 'rgba(0,0,0,.4)');
  R(c, x, y + 11, 12, 14, C.k); R(c, x + 1, y + 12, 10, 12, '#2a3446'); R(c, x + 1, y + 12, 10, 1, '#465672');
  for (let i = 0; i < 4; i++) R(c, x + 2 + i * 2, y + 16, 1, 2, C.amber);
  R(c, x + 3, y + 21, 6, 2, '#141a26');
}

export function switchBox(c, x, y) {
  R(c, x, y + 5, 16, 7, C.k); R(c, x + 1, y + 6, 14, 5, '#1c2638');
  for (let i = 0; i < 6; i++) R(c, x + 2 + i * 2, y + 8, 1, 2, i < 2 ? C.lime : C.deep);
  R(c, x + 14, y + 7, 1, 1, C.cyan);
}
export function routerBox(c, x, y) {
  R(c, x + 1, y + 6, 14, 7, C.k); R(c, x + 2, y + 7, 12, 5, '#2a2236');
  R(c, x + 3, y + 9, 2, 2, C.deep); R(c, x + 6, y + 9, 2, 2, C.deep); R(c, x + 12, y + 8, 1, 1, C.amber);
  R(c, x + 4, y + 2, 1, 4, C.metal2); R(c, x + 11, y + 2, 1, 4, C.metal2);
}

/** GPU rig: a rack chassis full of graphics cards, with a green glow through the mesh. */
export function gpuRig(c, x, y) {
  R(c, x, y + 31, 16, 2, 'rgba(0,0,0,.5)');
  R(c, x, y, 16, 31, C.k); R(c, x + 1, y + 1, 14, 29, '#141c14'); R(c, x + 1, y + 1, 14, 1, '#2f4a2c');
  for (let u = 0; u < 4; u++) {
    const yy = y + 4 + u * 6;
    R(c, x + 2, yy, 12, 4, '#1d2a1c'); R(c, x + 2, yy, 12, 1, '#34502f');
    for (let f = 0; f < 3; f++) R(c, x + 3 + f * 4, yy + 1, 2, 2, '#0b120b'); // fans
    R(c, x + 2, yy + 4, 12, 1, 'rgba(124,255,107,.18)');
  }
  R(c, x + 3, y + 28, 10, 1, '#0b120b');
}
/** Graphics card, for the inventory and shop. */
export function gpuCard(c, x, y) {
  R(c, x, y + 3, 16, 9, C.k); R(c, x + 1, y + 4, 14, 7, '#22301f'); R(c, x + 1, y + 4, 14, 1, '#3b5a34');
  for (const fx of [4, 11]) { c.fillStyle = '#0b120b'; c.beginPath(); c.arc(x + fx, y + 7.5, 2.6, 0, 7); c.fill(); R(c, x + fx, y + 7, 1, 1, '#3b5a34'); }
  R(c, x + 2, y + 12, 10, 2, '#b08a2e'); for (let i = 0; i < 5; i++) R(c, x + 3 + i * 2, y + 12, 1, 2, '#6b5418'); // gold fingers
  R(c, x + 14, y + 5, 1, 1, C.lime);
}
/** A bought rack, standing empty: the frame and rails, waiting for a device. */
export function rackFrame(c, x, y) {
  R(c, x, y + 31, 16, 2, 'rgba(0,0,0,.5)');
  R(c, x, y, 16, 31, C.k); R(c, x + 1, y + 1, 14, 29, '#0b111b');
  R(c, x + 1, y + 1, 14, 2, C.metal2); R(c, x + 1, y + 1, 1, 29, C.metal2); R(c, x + 14, y + 1, 1, 29, C.metal2);
  for (let u = 0; u < 9; u++) { R(c, x + 2, y + 5 + u * 3, 1, 1, C.metal3); R(c, x + 13, y + 5 + u * 3, 1, 1, C.metal3); }
}

/**
 * Every map sprite: texture size, where it sits inside its tile (off), its
 * visible box height (h, used for depth and outlines) and blinking LEDs.
 */
// port: where cables plug in, relative to the sprite's top-left.
export const SPRITES = {
  tower:      { w: 10, h: 17, off: [2, 10], box: [8, 15],  port: [6, 13], draw: (c) => tower(c, 0, 0), leds: [] },
  rack:       { w: 16, h: 33, off: [0, 0],  box: [16, 31], port: [8, 29], draw: (c) => rack(c, 0, 0), leds: RACK_LEDS },
  laptop:     { w: 15, h: 12, off: [1, 1],  box: [14, 11], port: [13, 9], draw: (c) => laptop(c, 0, 0), leds: [[2, 2]] },
  homerouter: { w: 15, h: 11, off: [1, 2],  box: [14, 10], port: [7, 9],  draw: (c) => homeRouter(c, 0, 0), leds: [[3, 7], [5, 7], [7, 7], [9, 7]] },
  ispbox:     { w: 13, h: 25, off: [2, -8], box: [12, 25], port: [6, 23], draw: (c) => ispBox(c, 0, 0), leds: [[3, 13], [5, 13]] },
  firewall:   { w: 15, h: 11, off: [1, 4],  box: [14, 9],  port: [7, 8],  draw: (c) => firewall(c, 0, 0), leds: [[3, 4], [6, 4], [9, 4]] },
  fibrepanel: { w: 13, h: 25, off: [2, -8], box: [12, 25], port: [6, 23], draw: (c) => fibrePanel(c, 0, 0), leds: [[3, 13], [9, 13]] },
  gpurig:     { w: 16, h: 33, off: [0, 0],  box: [16, 31], port: [8, 29], draw: (c) => gpuRig(c, 0, 0), leds: [[14, 2], [12, 2]] },
};

export function coil(c, col) {
  c.strokeStyle = C.k; c.lineWidth = 3; c.beginPath(); c.arc(8, 8, 5, 0, 7); c.stroke();
  c.strokeStyle = col; c.lineWidth = 1;
  c.beginPath(); c.arc(8, 8, 5, 0, 7); c.stroke();
  c.beginPath(); c.arc(8, 8, 3, 0, 7); c.stroke();
  R(c, 12, 11, 3, 2, '#c9e4f2');
}

/** 16x16 icon for an inventory slot or shop row. */
export function drawIcon(c, item) {
  c.clearRect(0, 0, 16, 16);
  if (item.category === 'cable') return coil(c, CABLE_COLORS[item.code] || C.cyan);
  switch (item.code) {
    case 'pc': return tower(c, 4, 0);
    case 'laptop': return laptop(c, 1, 3);
    case 'home_router': return homeRouter(c, 1, 3);
    case 'firewall': return firewall(c, 1, 4);
    case 'switch8': return switchBox(c, 0, 0);
    case 'router': return routerBox(c, 0, 0);
    case 'desk_kit': c.save(); c.scale(1 / 3, 1 / 3); desk(c, 0, 0); c.restore(); return;
    case 'server_rack': c.save(); c.scale(0.5, 0.5); rackFrame(c, 8, 0); c.restore(); return;
  }
  if (item.sprite === 'gpucard' || (item.category === 'part' && !SPRITES[item.sprite])) return gpuCard(c, 0, 0);
  switch (item.sprite) {
    case 'gpurig': c.save(); c.scale(0.5, 0.5); gpuRig(c, 8, 0); c.restore(); return;
    case 'rack': case 'rack2u': c.save(); c.scale(0.5, 0.5); rack(c, 0, 0); c.restore(); return;
  }
  // any other device: its map sprite, scaled down to fit and centred
  const sp = SPRITES[item.sprite];
  if (sp) {
    const k = Math.min(1, 16 / sp.w, 16 / sp.h);
    c.save(); c.translate(Math.floor((16 - sp.w * k) / 2), Math.floor((16 - sp.h * k) / 2)); c.scale(k, k); sp.draw(c); c.restore();
    return;
  }
  c.save(); c.scale(0.5, 0.5); rack(c, 0, 0); c.restore(); // nothing better: a rack unit
}

/* ======================= player ======================= */

const PLAYER = [
  '....kkkkk...', '...khhhhhk..', '..khhhhhhhk.', '.kchhhhhhhck', '.kcsssssshck', '..kswsswssk.',
  '..kskssksSk.', '...ksssSSk..', '..kjjcajjk..', '.kjjjcajjjk.', 'kjjjjcajjjjk', 'ksjjjjjjjjsk',
  'kSkJjjjjJkSk', '.k.kJJJJk.k.', '...knnnnk...', '...knkknk...', '...knk.knk..', '...kbk.kbk..', '...kkk.kkk..',
];
const PLAYER_PAL = { k: C.k, h: '#2a1d3d', s: '#e0a582', S: '#a8714f', j: '#11798f', J: '#0a4b5a', c: C.cyan, n: '#1c2233', b: '#0d1018', w: '#c9e4f2', a: C.magenta };
/** Avatar colourways (FR-1.4): hair, jacket (light/dark) and trim. Mirrors Accounts::AVATARS in PHP. */
export const AVATARS = {
  cyan:    { h: '#2a1d3d', j: '#11798f', J: '#0a4b5a', c: C.cyan,    a: C.magenta },
  magenta: { h: '#1a1420', j: '#9b2766', J: '#5e1640', c: C.magenta, a: C.cyan },
  lime:    { h: '#3b2a18', j: '#3f7d2c', J: '#254a1a', c: C.lime,    a: C.amber },
  amber:   { h: '#121212', j: '#a8661d', J: '#6a3f10', c: C.amber,   a: C.cyan },
  violet:  { h: '#5a3a1e', j: '#5b3c9e', J: '#372361', c: '#b494ff', a: C.lime },
  steel:   { h: '#c9c4bd', j: '#4a5a6e', J: '#2c3846', c: '#9fb3c8', a: C.amber },
  ember:   { h: '#7a2d17', j: '#9e3a24', J: '#5e2214', c: '#ff8a5c', a: C.cyan },
  mint:    { h: '#0f1a20', j: '#2b8a74', J: '#195446', c: '#6ff0c8', a: C.magenta },
};
/** Characters who walk the map besides the player. Vex the broker: dark coat, shades, amber trim. */
export const NPCS = {
  broker: { h: '#0b0b10', j: '#6a5a82', J: '#433858', c: C.amber, a: C.magenta, w: '#05070d', s: '#c98f6b', S: '#8f5f42', n: '#121219' },
};
export const PLAYER_SIZE = { w: 12, h: 21 };
export function player(c, avatar = 'cyan') {
  playerFrame(c, avatar, 'idle0');
}

// Legs (rows 14-18). A walk is a stride: legs apart (one forward, one back), then passing
// (legs together, body up a pixel), then apart again with the front foot just landing.
// Scissoring legs read as walking in either direction, so the character never moonwalks.
const LEGS = {
  stand:   PLAYER.slice(14),
  apart:   ['...knnnnk...', '..knkkknk...', '..knk..knk..', '.kbk....kbk.', '.kkk....kkk.'],
  landing: ['...knnnnk...', '..knkkknk...', '..knk..knk..', '.kbk...kbk..', '.kkk...kkk..'],
  passing: ['...knnnnk...', '....knnk....', '....knnk....', '....kbbk....', '....kkkk....'],
};
/** The player animation frames: idle0/idle1 (breathing: the head bobs a pixel), walk0-2 (stride). */
export const PLAYER_FRAMES = ['idle0', 'idle1', 'walk0', 'walk1', 'walk2'];
export function playerFrame(c, avatar = 'cyan', pose = 'idle0') {
  const pal = { ...PLAYER_PAL, ...(AVATARS[avatar] || NPCS[avatar] || {}) };
  const row = (text, y) => { for (let i = 0; i < text.length; i++) if (text[i] !== '.') R(c, i, y, 1, 1, pal[text[i]]); };
  const legs = { walk0: LEGS.passing, walk1: LEGS.apart, walk2: LEGS.landing }[pose] || LEGS.stand;
  const lift = pose === 'walk0' ? 1 : 0;                           // passing step: the body rises a pixel
  c.fillStyle = 'rgba(0,0,0,.45)'; c.beginPath(); c.ellipse(6, 19, pose === 'walk1' ? 6 : 5, 2, 0, 0, 7); c.fill();
  legs.forEach((r, j) => row(r, 14 + j - lift));
  for (let j = 8; j <= 13; j++) row(PLAYER[j], j - lift);         // body
  const bob = pose === 'idle1' ? 1 : 0;                            // breathing: the head dips a pixel
  for (let j = 0; j <= 7; j++) row(PLAYER[j], j + bob - lift);
}

/** Draws an avatar into every <canvas data-avatar="..."> on the page, scaled up crisp. */
export function paintAvatars(root = document) {
  root.querySelectorAll('canvas[data-avatar]').forEach((cv) => {
    const scale = Number(cv.dataset.scale || 3);
    cv.width = PLAYER_SIZE.w * scale; cv.height = PLAYER_SIZE.h * scale;
    const ctx = cv.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    ctx.save(); ctx.scale(scale, scale); player(ctx, cv.dataset.avatar); ctx.restore();
  });
}

/* ======================= garage ======================= */

function garage(c, loc) {
  const W = COLS * TILE, H = ROWS * TILE, wallH = loc.wallRows * TILE;
  // cinder-block back wall
  R(c, 0, 0, W, wallH, '#141a24');
  for (let row = 0; row < wallH / 8; row++) {
    const y = row * 8, shift = row % 2 ? 8 : 0;
    R(c, 0, y + 7, W, 1, '#0d121a');
    for (let x = -shift; x < W; x += 16) R(c, x + 15, y, 1, 7, '#0d121a');
    for (let x = -shift; x < W; x += 16) if (hash(x, row) > 0.7) R(c, x + 2 + Math.floor(hash(row, x) * 10), y + 2, 2, 1, '#18202c');
  }
  R(c, 0, 0, W, 2, '#1d2533'); R(c, 0, wallH - 2, W, 2, '#07090e');

  // pegboard with tools above the bench
  R(c, 18, 4, 60, 24, '#05070d'); R(c, 19, 5, 58, 22, '#1a2630');
  for (let y = 7; y < 26; y += 3) for (let x = 21; x < 76; x += 3) R(c, x, y, 1, 1, '#0e161d');
  R(c, 24, 9, 2, 12, '#7f8ea3'); R(c, 23, 9, 4, 3, '#7f8ea3');                       // wrench
  R(c, 32, 8, 1, 9, '#9aa7b6'); R(c, 31, 17, 3, 5, C.magenta);                       // screwdriver
  c.strokeStyle = C.cyan; c.lineWidth = 1; c.beginPath(); c.arc(46.5, 15.5, 5, 0, 7); c.stroke(); // patch cable
  c.beginPath(); c.arc(46.5, 15.5, 3, 0, 7); c.stroke();
  R(c, 58, 10, 2, 9, '#5b6b80'); R(c, 62, 10, 2, 9, '#5b6b80'); R(c, 58, 18, 6, 2, C.amber); // crimper
  R(c, 68, 9, 6, 8, '#2b3a4a'); R(c, 69, 10, 4, 2, C.lime);                           // cable tester

  // fluorescent tube
  R(c, 88, 3, 60, 3, '#05070d'); R(c, 90, 4, 56, 1, '#e8fbff');
  R(c, 86, 6, 64, 2, 'rgba(232,251,255,.10)');

  // small night window
  R(c, 216, 7, 52, 20, '#2a3850'); R(c, 218, 9, 48, 16, '#081026');
  R(c, 241, 9, 2, 16, '#2a3850');
  R(c, 226, 12, 3, 3, '#dfe7ef'); R(c, 227, 12, 2, 1, '#081026');                       // moon
  for (const [sx, sy] of [[221, 20], [234, 11], [250, 14], [258, 21], [262, 11]]) R(c, sx, sy, 1, 1, '#8aa3bb');
  R(c, 245, 19, 21, 6, '#0b1430'); R(c, 252, 17, 6, 8, '#0b1430'); R(c, 254, 19, 1, 1, C.amber); R(c, 248, 21, 1, 1, C.amber);

  // wall power outlet under the ISP box
  R(c, 186, 20, 6, 8, '#05070d'); R(c, 187, 21, 4, 6, '#9aa7b6'); R(c, 188, 23, 1, 2, C.k); R(c, 190, 23, 1, 2, C.k);

  // concrete floor
  const floorBottom = loc.bottomWall ? H - TILE : H;
  for (let ty = loc.wallRows; ty * TILE < floorBottom; ty++) for (let tx = 0; tx < COLS; tx++) {
    const x = tx * TILE, y = ty * TILE;
    R(c, x, y, 16, 16, '#131922');
    for (let k = 0; k < 4; k++) {
      const h = hash(tx * 5 + k, ty * 3);
      R(c, x + Math.floor(h * 16), y + Math.floor(hash(ty * 7, tx + k) * 16), 1, 1, h > 0.5 ? '#171e28' : '#10151d');
    }
  }
  for (let x = 80; x < W; x += 80) R(c, x, wallH, 1, floorBottom - wallH, '#0d1219');
  R(c, 0, wallH + 80, W, 1, '#0d1219');
  for (const [cx, cy] of [[52, 132], [53, 133], [54, 133], [55, 134], [57, 134], [58, 135], [130, 70], [131, 71], [131, 72], [132, 73]]) R(c, cx, cy, 1, 1, '#0a0e14');
  c.fillStyle = 'rgba(0,0,0,.35)'; c.beginPath(); c.ellipse(196, 150, 10, 5, 0, 0, 7); c.fill(); // oil stain

  // side walls
  R(c, 0, wallH, 3, floorBottom - wallH, '#0b0f16'); R(c, W - 3, wallH, 3, floorBottom - wallH, '#0b0f16');

  // roller door along the bottom, streetlight leaking under it
  if (loc.bottomWall) {
    R(c, 0, floorBottom, W, TILE, '#0e131b');
    R(c, 128, floorBottom, 176, TILE, '#1b2230');
    for (let y = floorBottom + 2; y < H; y += 3) R(c, 128, y, 176, 1, '#10151e');
    R(c, 212, floorBottom + 9, 8, 2, '#3a4d6e');
    R(c, 128, floorBottom - 1, 176, 1, 'rgba(255,180,61,.35)');
    R(c, 128, floorBottom - 3, 176, 2, 'rgba(255,180,61,.07)');
    R(c, 124, floorBottom, 4, TILE, '#2a3850'); R(c, 304, floorBottom, 4, TILE, '#2a3850');
  }

  // anti-fatigue mat in front of the bench
  R(c, 20, 50, 56, 12, '#0d1118');
  for (let x = 22; x < 74; x += 4) R(c, x, 52, 2, 8, '#111720');

  for (const f of loc.furniture) FURNITURE[f.type]?.(c, f.x * TILE, f.y * TILE);
}

const FURNITURE = {
  workbench(c, x, y) {
    R(c, x + 1, y + 16, 62, 3, 'rgba(0,0,0,.45)');
    R(c, x, y, 64, 16, C.k);
    R(c, x + 1, y + 1, 62, 11, '#3b2e26');
    for (const py of [4, 8]) R(c, x + 1, y + py, 62, 1, '#31261f');
    R(c, x + 1, y + 1, 62, 1, '#4d3c31');
    R(c, x + 1, y + 12, 62, 3, '#211914');
    R(c, x + 2, y + 15, 3, 2, C.k); R(c, x + 59, y + 15, 3, 2, C.k);
    R(c, x + 3, y + 2, 7, 6, '#4a5568'); R(c, x + 4, y + 3, 5, 2, '#6b7a90');      // vise
    R(c, x + 55, y + 2, 5, 3, '#05070d'); R(c, x + 57, y + 5, 1, 5, '#2c3b56'); R(c, x + 55, y + 9, 5, 2, '#2c3b56'); // lamp
    R(c, x + 50, y + 8, 12, 6, 'rgba(255,180,61,.10)');
  },
  shelf(c, x, y) {
    R(c, x + 1, y + 16, 30, 3, 'rgba(0,0,0,.45)');
    R(c, x, y, 32, 16, C.k);
    R(c, x + 1, y + 1, 30, 13, '#1f2a3a');
    for (const py of [4, 8, 12]) R(c, x + 1, y + py, 30, 1, '#172030');
    for (const px of [0, 31]) R(c, x + px, y, 1, 16, '#3a4d6e');
    R(c, x + 1, y + 14, 30, 2, '#121924');
  },
  toolchest(c, x, y) {
    R(c, x + 1, y + 15, 14, 3, 'rgba(0,0,0,.45)');
    R(c, x, y, 16, 16, C.k); R(c, x + 1, y + 1, 14, 14, '#6b1d40'); R(c, x + 1, y + 1, 14, 1, '#8a2953');
    for (const py of [4, 8, 12]) { R(c, x + 1, y + py, 14, 1, '#4a1430'); R(c, x + 6, y + py - 2, 4, 1, '#c9d3de'); }
  },
  boxes(c, x, y) {
    R(c, x + 1, y + 31, 15, 2, 'rgba(0,0,0,.45)');
    for (const [by, bh] of [[0, 15], [16, 15]]) {
      R(c, x + 1, y + by, 15, bh, C.k); R(c, x + 2, y + by + 1, 13, bh - 2, '#5a4632');
      R(c, x + 2, y + by + 1, 13, 1, '#6e573f'); R(c, x + 8, y + by + 1, 1, bh - 2, '#c2a77d');
    }
  },
  bike(c, x, y) {
    c.strokeStyle = '#05070d'; c.lineWidth = 2;
    for (const wx of [7, 25]) { c.beginPath(); c.arc(x + wx, y + 9, 5, 0, 7); c.stroke(); }
    c.strokeStyle = '#3a4d6e'; c.lineWidth = 1;
    for (const wx of [7, 25]) { c.beginPath(); c.arc(x + wx + 0.5, y + 9.5, 5, 0, 7); c.stroke(); }
    c.strokeStyle = C.cyan; c.beginPath(); c.moveTo(x + 7.5, y + 9.5); c.lineTo(x + 15.5, y + 4.5); c.lineTo(x + 25.5, y + 9.5); c.lineTo(x + 13.5, y + 9.5); c.closePath(); c.stroke();
    R(c, x + 13, y + 2, 5, 2, C.k); R(c, x + 21, y + 3, 4, 1, C.k);
  },
  car(c, x, y) {
    // top-down hatchback, nose toward the roller door
    const bx = x + 8, bw = 48, by = y + 2, bh = 92;
    rrect(c, bx - 2, by + 3, bw + 4, bh, 8, 'rgba(0,0,0,.45)');                        // shadow
    for (const wy of [12, 66]) { R(c, bx - 3, by + wy, 5, 15, C.k); R(c, bx + bw - 2, by + wy, 5, 15, C.k); // tyres
      R(c, bx - 3, by + wy + 2, 1, 11, '#1a2230'); R(c, bx + bw + 2, by + wy + 2, 1, 11, '#1a2230'); }
    rrect(c, bx, by, bw, bh, 7, C.k);
    rrect(c, bx + 1, by + 1, bw - 2, bh - 2, 6, '#1d4450');
    R(c, bx + 3, by + 8, 2, bh - 16, '#2a5f6d');                                       // light down the left flank
    R(c, bx + bw - 5, by + 8, 2, bh - 16, '#163a44');                                  // shade on the right
    rrect(c, bx + 2, by + 1, bw - 4, 5, 3, '#173a44');                                 // rear bumper
    R(c, bx + 4, by + 2, 7, 2, '#a8285e'); R(c, bx + bw - 11, by + 2, 7, 2, '#a8285e'); // tail lights
    trap(c, bx + 6, by + 9, bw - 12, 11, -3, '#0a1820');                               // rear window
    R(c, bx + 10, by + 11, 10, 1, '#2a4f5d');
    rrect(c, bx + 6, by + 21, bw - 12, 32, 4, '#225360');                              // roof
    R(c, bx + 8, by + 23, 1, 28, '#2e6a78'); R(c, bx + bw - 9, by + 23, 1, 28, '#2e6a78'); // roof rails
    trap(c, bx + 5, by + 54, bw - 10, 14, 3, '#0a1820');                               // windscreen
    R(c, bx + 9, by + 57, 14, 1, '#2a4f5d'); R(c, bx + 9, by + 59, 8, 1, '#22414d');
    R(c, bx - 3, by + 53, 4, 3, '#1d4450'); R(c, bx + bw - 1, by + 53, 4, 3, '#1d4450'); // mirrors
    R(c, bx + bw / 2 - 1, by + 70, 2, 18, '#1a3d47');                                  // bonnet crease
    R(c, bx + 3, by + bh - 5, 9, 3, '#dfe7ef'); R(c, bx + bw - 12, by + bh - 5, 9, 3, '#dfe7ef'); // headlights
    R(c, bx + 4, by + bh - 1, 7, 3, 'rgba(232,251,255,.10)'); R(c, bx + bw - 11, by + bh - 1, 7, 3, 'rgba(232,251,255,.10)');
  },
  desk: (c, x, y) => desk(c, x, y),
  cooling: (c, x, y) => cooling(c, x, y),
  rack: (c, x, y) => rackFrame(c, x, y),
  meeting(c, x, y) { // 5x2: a meeting table with chairs round it
    R(c, x + 6, y + 27, 70, 3, 'rgba(0,0,0,.4)');
    for (const cx of [12, 28, 44, 60]) { R(c, x + cx, y, 8, 6, C.k); R(c, x + cx + 1, y + 1, 6, 4, '#24314a'); R(c, x + cx, y + 25, 8, 6, C.k); R(c, x + cx + 1, y + 26, 6, 4, '#24314a'); }
    R(c, x + 4, y + 6, 72, 20, C.k); R(c, x + 5, y + 7, 70, 18, '#5a3f2a'); R(c, x + 5, y + 7, 70, 1, '#7a5a3e');
    for (let k = 9; k < 75; k += 9) R(c, x + k, y + 8, 1, 16, '#4e3624');
    R(c, x + 14, y + 12, 9, 6, '#1c2638'); R(c, x + 15, y + 13, 7, 3, C.cyan);        // laptop
    R(c, x + 50, y + 13, 8, 5, '#e6ecf2'); R(c, x + 51, y + 14, 6, 1, '#9fb0c2');    // notes
    R(c, x + 36, y + 11, 6, 6, '#2a3446'); R(c, x + 37, y + 12, 4, 4, '#05070d');    // conference phone
  },
  noc(c, x, y) { // 4x2: an operator console with a row of monitors
    R(c, x + 2, y + 28, 60, 3, 'rgba(0,0,0,.4)');
    for (let i = 0; i < 4; i++) {
      const mx = x + 2 + i * 15;
      R(c, mx, y + 1, 14, 11, C.k); R(c, mx + 1, y + 2, 12, 8, '#04121a');
      for (let k = 0; k < 10; k++) R(c, mx + 2 + k, y + 8 - Math.floor(hash(i, k) * 5), 1, 1, [C.cyan, C.lime, C.amber, C.cyan][i]);
      R(c, mx + 6, y + 12, 2, 3, C.metal2);
    }
    R(c, x, y + 14, 64, 10, C.k); R(c, x + 1, y + 15, 62, 7, '#24314a'); R(c, x + 1, y + 15, 62, 1, C.metal3);
    for (const kx of [8, 23, 38, 53]) { R(c, x + kx, y + 17, 8, 3, '#121a29'); }
    for (const cx of [10, 40]) { R(c, x + cx, y + 25, 10, 6, C.k); R(c, x + cx + 1, y + 26, 8, 4, '#1c2638'); }
  },
  switchgear(c, x, y) { // 3x2: electrical switchgear cabinets
    R(c, x + 1, y + 29, 46, 3, 'rgba(0,0,0,.45)');
    for (let i = 0; i < 3; i++) {
      const cx = x + i * 16;
      R(c, cx, y, 16, 29, C.k); R(c, cx + 1, y + 1, 14, 27, '#8d97a3'); R(c, cx + 1, y + 1, 14, 1, '#b3bcc6');
      R(c, cx + 3, y + 4, 10, 6, '#2a313d'); R(c, cx + 5, y + 6, 3, 2, hash(i, 1) > 0.5 ? C.lime : C.amber);
      R(c, cx + 9, y + 6, 2, 2, '#d23a3a');
      R(c, cx + 4, y + 13, 8, 1, '#5d6670'); R(c, cx + 4, y + 16, 8, 1, '#5d6670');
      R(c, cx + 5, y + 20, 6, 5, '#f2c230'); R(c, cx + 7, y + 21, 2, 3, C.k);     // high-voltage sign
    }
  },
  batteries(c, x, y) { // 2x2: UPS battery cabinets
    R(c, x + 1, y + 29, 30, 3, 'rgba(0,0,0,.45)');
    for (let i = 0; i < 2; i++) {
      const cx = x + i * 16;
      R(c, cx, y, 16, 29, C.k); R(c, cx + 1, y + 1, 14, 27, '#1b2230'); R(c, cx + 1, y + 1, 14, 1, '#2c3b56');
      for (let r = 0; r < 5; r++) { R(c, cx + 3, y + 4 + r * 5, 10, 3, '#111722'); R(c, cx + 11, y + 5 + r * 5, 1, 1, C.lime); }
    }
  },
  genset(c, x, y) { // 4x3: the standby diesel generator
    R(c, x + 2, y + 44, 62, 3, 'rgba(0,0,0,.5)');
    R(c, x, y + 8, 64, 37, C.k); R(c, x + 1, y + 9, 62, 35, '#d9a03a'); R(c, x + 1, y + 9, 62, 2, '#f0c060');
    for (let k = 6; k < 40; k += 4) R(c, x + k, y + 15, 2, 14, '#a87a24');      // louvres
    R(c, x + 44, y + 14, 15, 15, '#3a3324'); R(c, x + 46, y + 16, 11, 11, '#1e1a12'); // radiator
    R(c, x + 6, y + 34, 26, 6, '#1e1a12'); R(c, x + 8, y + 36, 6, 2, C.lime); R(c, x + 16, y + 36, 10, 2, '#4a6a8a'); // control panel
    R(c, x + 50, y, 5, 10, '#3a4250'); R(c, x + 49, y, 7, 2, '#5d6670');         // exhaust stack
    R(c, x + 36, y + 34, 22, 6, '#f2c230'); for (let k = 0; k < 22; k += 4) R(c, x + 36 + k, y + 34, 2, 6, C.k); // hazard stripe
  },
  plant(c, x, y) {
    const p = { x: x + 8, y };
    R(c, p.x, p.y + 6, 10, 10, C.k); R(c, p.x + 1, p.y + 7, 8, 8, '#1d2a1d');
    R(c, p.x + 2, p.y + 2, 2, 6, '#2f6b3a'); R(c, p.x + 5, p.y, 2, 8, '#3c8a46'); R(c, p.x + 3, p.y + 4, 5, 3, '#3c8a46');
  },
};

/* ======================= office ======================= */

function desk(c, x, y) {
  R(c, x + 2, y + 27, 46, 3, 'rgba(0,0,0,.4)');
  R(c, x, y + 6, 48, 18, C.k); R(c, x + 1, y + 7, 46, 15, '#24314a'); R(c, x + 1, y + 7, 46, 1, C.metal3); R(c, x + 1, y + 22, 46, 4, '#121a29');
  R(c, x + 2, y + 26, 2, 2, C.k); R(c, x + 44, y + 26, 2, 2, C.k);
  R(c, x + 15, y + 1, 18, 11, C.k); R(c, x + 16, y + 2, 16, 8, '#0a1622');
  R(c, x + 22, y + 12, 4, 2, C.k); R(c, x + 20, y + 13, 8, 1, C.metal2);
  R(c, x + 14, y + 16, 20, 5, C.k);
  for (let i = 0; i < 8; i++) { R(c, x + 15 + i * 2, y + 17, 1, 1, C.metal3); R(c, x + 15 + i * 2, y + 19, 1, 1, C.metal3); }
  R(c, x + 5, y + 10, 5, 6, C.k); R(c, x + 6, y + 11, 3, 4, '#c9e4f2'); R(c, x + 6, y + 11, 3, 1, '#6b3b2a');
  R(c, x + 17, y + 29, 14, 9, C.k); R(c, x + 18, y + 30, 12, 7, '#1d2638'); R(c, x + 18, y + 30, 12, 2, C.metal2); R(c, x + 16, y + 37, 16, 3, '#273349');
}

/** Lights a desk monitor once a PC sits next to it. */
export function monitorOn(c, tx, ty) {
  const x = tx * TILE, y = ty * TILE;
  R(c, x + 10, y + 16, 30, 10, 'rgba(43,227,255,.07)');
  R(c, x + 16, y + 2, 16, 8, '#06323d');
  R(c, x + 17, y + 3, 9, 1, C.cyan); R(c, x + 17, y + 5, 12, 1, '#1590a6'); R(c, x + 17, y + 7, 6, 1, C.lime);
}

function cooling(c, x, y) {
  R(c, x, y + 45, 30, 2, 'rgba(0,0,0,.5)');
  R(c, x, y, 30, 45, C.k); R(c, x + 1, y + 1, 28, 43, '#18222f'); R(c, x + 1, y + 1, 28, 1, C.metal2);
  for (let f = 0; f < 2; f++) { c.fillStyle = '#0b111b'; c.beginPath(); c.arc(x + 15, y + 11 + f * 20, 8, 0, 7); c.fill(); }
  R(c, x + 4, y + 40, 15, 1, C.metal2); // vent grille
}

function office(c, loc) {
  const W = COLS * TILE, H = ROWS * TILE, wallH = loc.wallRows * TILE, px = loc.partition.col * TILE;
  R(c, 0, 0, W, wallH, '#0c1320'); R(c, px + 16, 0, W - px - 16, wallH, '#090e18');
  for (let x = 0; x < W; x += 32) R(c, x, 2, 1, wallH - 4, '#0a101b');
  R(c, 0, 0, W, 2, '#1a2436'); R(c, 0, wallH - 2, W, 2, '#05080e');
  R(c, px + 20, 7, W - px - 24, 1, C.cyan); R(c, px + 20, 6, W - px - 24, 1, 'rgba(43,227,255,.25)');
  R(c, px + 20, 8, W - px - 24, 1, 'rgba(43,227,255,.25)'); R(c, px + 20, 9, W - px - 24, 4, 'rgba(43,227,255,.05)');
  R(c, 16, 5, 72, 22, '#1a2436'); R(c, 18, 7, 68, 18, '#081026');
  for (let i = 0; i < 9; i++) {
    const h = 6 + Math.floor(hash(i, 3) * 11), w = 6 + Math.floor(hash(i, 9) * 4), bx = (18 + i * 7.5) | 0;
    R(c, bx, 25 - h, w, h, '#0e1a38');
    for (let k = 0; k < 5; k++) if (hash(i, k + 20) > 0.55) R(c, bx + 1 + Math.floor(hash(i, k) * (w - 2)), 25 - h + 2 + Math.floor(hash(k, i) * (h - 3)), 1, 1, hash(k, i + 5) > 0.7 ? C.cyan : C.amber);
  }
  R(c, 51, 7, 2, 18, '#1a2436');
  R(c, 104, 8, 52, 18, '#2a3850'); R(c, 105, 9, 50, 16, '#152033');
  R(c, 110, 12, 5, 4, C.cyan); R(c, 128, 12, 5, 4, C.cyan); R(c, 145, 18, 5, 4, C.amber); R(c, 115, 14, 13, 1, C.cyan);
  R(c, 133, 14, 6, 1, '#1590a6'); R(c, 139, 14, 1, 5, '#1590a6'); R(c, 139, 19, 6, 1, '#1590a6');
  R(c, 112, 21, 22, 1, C.magenta); R(c, 130, 20, 1, 3, C.magenta);
  if (!roomFloors(c, loc)) for (let ty = loc.wallRows; ty < ROWS; ty++) for (let tx = 0; tx < COLS; tx++) {
    const x = tx * TILE, y = ty * TILE;
    if (tx < loc.partition.col) {
      R(c, x, y, 16, 16, (tx + ty) % 2 ? '#0d1421' : '#0f1726');
      for (let k = 0; k < 3; k++) R(c, x + Math.floor(hash(tx * 3 + k, ty) * 15), y + Math.floor(hash(ty, tx + k) * 15), 1, 1, '#15203a');
    } else if (tx > loc.partition.col) {
      R(c, x, y, 16, 16, '#121b29'); R(c, x, y, 16, 1, '#1a2638'); R(c, x, y, 1, 16, '#1a2638');
      R(c, x + 15, y, 1, 16, '#080c14'); R(c, x, y + 15, 16, 1, '#080c14');
      if ((loc.aisles || [5, 10]).includes(ty)) for (let py = 3; py < 14; py += 3) for (let pxx = 3; pxx < 14; pxx += 3) R(c, x + pxx, y + py, 1, 1, '#1c3d4f');
    } else R(c, x, y, 16, 16, '#0f1726');
  }
  const doors = loc.partition.doorRows, doorTop = doors[0] * TILE, doorBottom = (doors[doors.length - 1] + 1) * TILE;
  for (let y = wallH; y < H; y++) {
    if (y >= doorTop && y < doorBottom) continue;
    R(c, px + 3, y, 3, 1, 'rgba(160,220,255,.16)'); R(c, px + 3, y, 1, 1, 'rgba(160,220,255,.35)');
  }
  for (const y of [wallH, doorTop - 2, doorBottom, H - 2]) R(c, px + 1, y, 6, 2, '#2a3850');
  R(c, px + 2, doorTop, 4, doorBottom - doorTop, '#151e2e');
  interiorWalls(c, loc);
  videoWalls(c, loc);
  for (const f of loc.furniture) FURNITURE[f.type]?.(c, f.x * TILE, f.y * TILE);
  roomLabels(c, loc);
}

/* ======================= shared: rooms and walls ======================= */

/** One floor tile in a room's style. */
function floorTile(c, kind, tx, ty, loc) {
  const x = tx * TILE, y = ty * TILE;
  if (kind === 'raised') { // raised datacentre floor, perforated on the cold aisles
    R(c, x, y, 16, 16, '#111a27'); R(c, x, y, 16, 1, '#19253a'); R(c, x, y, 1, 16, '#19253a');
    R(c, x + 15, y, 1, 16, '#070b12'); R(c, x, y + 15, 16, 1, '#070b12');
    if ((loc.aisles || []).includes(ty)) for (let py = 3; py < 14; py += 3) for (let px = 3; px < 14; px += 3) R(c, x + px, y + py, 1, 1, '#1c3d4f');
  } else if (kind === 'wood') {
    R(c, x, y, 16, 16, (ty % 2) ? '#2b2018' : '#30241a');
    for (const py of [3, 7, 11, 15]) R(c, x, y + py, 16, 1, '#1f1711');
    R(c, x + ((tx * 5 + ty * 3) % 12) + 2, y + 1 + (ty % 3) * 4, 1, 2, '#1f1711');
  } else if (kind === 'noc') {
    R(c, x, y, 16, 16, (tx + ty) % 2 ? '#0b1224' : '#0d152a');
    if (hash(tx, ty) > 0.6) R(c, x + Math.floor(hash(ty, tx) * 14), y + Math.floor(hash(tx + 3, ty) * 14), 1, 1, '#1b2a4a');
  } else if (kind === 'concrete') {
    R(c, x, y, 16, 16, '#1b1f26'); R(c, x, y, 16, 1, '#22272f'); R(c, x + 15, y, 1, 16, '#14171c');
    for (let k = 0; k < 4; k++) R(c, x + Math.floor(hash(tx * 4 + k, ty) * 15), y + Math.floor(hash(ty, tx + k) * 15), 1, 1, '#2a2f38');
  } else { // carpet
    R(c, x, y, 16, 16, (tx + ty) % 2 ? '#0d1421' : '#0f1726');
    for (let k = 0; k < 3; k++) R(c, x + Math.floor(hash(tx * 3 + k, ty) * 15), y + Math.floor(hash(ty, tx + k) * 15), 1, 1, '#15203a');
  }
}

/** Floors for every room (layout.json "rooms"). Returns false when the place has no rooms. */
function roomFloors(c, loc) {
  if (!loc.rooms) return false;
  // under walls and between rooms: the place's base floor
  for (let ty = loc.wallRows; ty < ROWS; ty++) for (let tx = 0; tx < COLS; tx++) floorTile(c, loc.baseFloor || 'carpet', tx, ty, loc);
  for (const r of loc.rooms) for (let ty = r.y; ty < r.y + r.h; ty++) for (let tx = r.x; tx < r.x + r.w; tx++) floorTile(c, r.floor, tx, ty, loc);
  // doorways in interior walls get the floor of the room they open into
  for (const w of loc.walls || []) for (const [dx, dy] of w.doors || []) {
    const r = loc.rooms.find((q) => dx >= q.x - 1 && dx <= q.x + q.w && dy >= q.y - 1 && dy <= q.y + q.h && q.floor !== 'raised') || loc.rooms[0];
    floorTile(c, r.floor, dx, dy, loc);
  }
  return true;
}

/** Interior walls: solid ones with a lit top edge, glass ones you can see through. */
function interiorWalls(c, loc) {
  for (const w of loc.walls || []) {
    const doors = new Set((w.doors || []).map(([x, y]) => `${x},${y}`));
    const vertical = w.h > w.w;
    for (let i = 0; i < w.w; i++) for (let j = 0; j < w.h; j++) {
      const tx = w.x + i, ty = w.y + j, x = tx * TILE, y = ty * TILE;
      if (doors.has(`${tx},${ty}`)) { // a doorway: frame posts either side
        if (vertical) { R(c, x + 4, y, 8, 1, '#2a3850'); R(c, x + 4, y + 15, 8, 1, '#2a3850'); }
        else { R(c, x, y + 4, 1, 8, '#2a3850'); R(c, x + 15, y + 4, 1, 8, '#2a3850'); }
        continue;
      }
      if (w.style === 'glass') {
        if (vertical) {
          R(c, x + 6, y, 4, 16, 'rgba(160,220,255,.16)'); R(c, x + 6, y, 1, 16, 'rgba(160,220,255,.35)');
          if (j % 3 === 0) R(c, x + 5, y, 6, 2, '#2a3850');
        } else {
          R(c, x, y + 6, 16, 4, 'rgba(160,220,255,.16)'); R(c, x, y + 6, 16, 1, 'rgba(160,220,255,.35)');
          if (i % 3 === 0) R(c, x, y + 5, 2, 6, '#2a3850');
        }
      } else if (vertical) {
        R(c, x + 3, y, 10, 16, '#1a2436'); R(c, x + 3, y, 2, 16, '#2a3850'); R(c, x + 11, y, 2, 16, '#0a0f19');
      } else {
        R(c, x, y + 2, 16, 12, '#1a2436'); R(c, x, y + 2, 16, 2, '#2a3850'); R(c, x, y + 10, 16, 4, '#121a29');
      }
    }
  }
}

/** A video wall on the wall above a room with "screens": maps, graphs and alerts. */
function videoWalls(c, loc) {
  for (const r of loc.rooms || []) {
    if (!r.screens) continue;
    const x0 = r.x * TILE + 3, w = r.w * TILE - 6, y0 = (r.y - 1) * TILE + 2;
    const panels = Math.max(2, Math.round(w / 40)), pw = Math.floor((w - (panels - 1) * 2) / panels);
    for (let i = 0; i < panels; i++) {
      const px = x0 + i * (pw + 2);
      R(c, px, y0, pw, 12, '#1a2436'); R(c, px + 1, y0 + 1, pw - 2, 10, '#04121a');
      if (i % 3 === 1) { // a world map with links
        for (let k = 0; k < 12; k++) R(c, px + 2 + Math.floor(hash(k, i) * (pw - 4)), y0 + 3 + Math.floor(hash(i, k) * 6), 2, 1, '#1d4a5a');
        R(c, px + 3, y0 + 5, pw - 6, 1, 'rgba(43,227,255,.5)');
      } else if (i % 3 === 2) { // alerts
        for (let k = 0; k < 4; k++) R(c, px + 2, y0 + 2 + k * 2, Math.floor(hash(k, i + 7) * (pw - 4)) + 2, 1, k === 1 ? C.magenta : C.lime);
      } else { // traffic graph
        let gy = y0 + 7;
        for (let k = 2; k < pw - 2; k++) { gy = Math.max(y0 + 2, Math.min(y0 + 9, gy + Math.round((hash(k, i + r.x) - 0.5) * 3))); R(c, px + k, gy, 1, 1, C.cyan); }
      }
    }
  }
}

/** Room names, faint on the floor (each room's "label" tile, or its bottom-left corner). */
function roomLabels(c, loc) {
  c.font = 'bold 7px monospace';
  c.textBaseline = 'alphabetic';
  for (const r of loc.rooms || []) {
    if (!r.name) continue;
    const [lx, ly] = r.label || [r.x, r.y + r.h - 1];
    const text = r.name.toUpperCase();
    c.fillStyle = 'rgba(0,0,0,.45)'; c.fillText(text, lx * TILE + 4, ly * TILE + 12);
    c.fillStyle = 'rgba(201,228,242,.42)'; c.fillText(text, lx * TILE + 3, ly * TILE + 11);
  }
}

/* ======================= shared ======================= */

/* ======================= datacentre ======================= */

function datacentre(c, loc) {
  const W = COLS * TILE, H = ROWS * TILE, wallH = loc.wallRows * TILE;
  // wall with the network operations display and a cyan light strip
  R(c, 0, 0, W, wallH, '#080d16');
  for (let x = 0; x < W; x += 40) R(c, x, 2, 1, wallH - 4, '#060a12');
  R(c, 0, 0, W, 2, '#162033'); R(c, 0, wallH - 2, W, 2, '#04060b');
  R(c, 8, 6, W - 16, 1, C.cyan); R(c, 8, 5, W - 16, 1, 'rgba(43,227,255,.25)'); R(c, 8, 7, W - 16, 1, 'rgba(43,227,255,.25)');
  const hall = loc.rooms ? loc.rooms[0].w * TILE : W; // the data hall's share of the back wall
  const screens = hall > 400 ? [[24, 96], [hall / 2 - 48, 96], [hall - 120, 96]] : [[24, 96], [hall - 120, 96]];
  for (const [sx, w] of screens) {
    R(c, sx, 10, w, 19, '#1a2436'); R(c, sx + 1, 11, w - 2, 17, '#04121a');
    let y = 24;
    for (let i = 0; i < w - 6; i++) { // traffic graph
      y = Math.max(14, Math.min(26, y + Math.round((hash(i, sx) - 0.5) * 4)));
      R(c, sx + 3 + i, y, 1, 1, sx < 100 ? C.cyan : sx > W - 200 ? C.amber : C.lime);
    }
  }
  // raised floor, with perforated tiles on the cold aisles (or each room's own floor)
  if (!roomFloors(c, loc)) for (let ty = loc.wallRows; ty < ROWS; ty++) for (let tx = 0; tx < COLS; tx++) floorTile(c, 'raised', tx, ty, loc);
  // yellow safety line along the walkway, in the data hall
  for (let x = 0; x < hall; x += 6) R(c, x, (loc.safetyRow ?? 9) * TILE + 1, 3, 1, '#6b5418');
  // the power room: a hazard stripe along the floor in front of the switchgear
  const power = (loc.rooms || []).find((r) => r.floor === 'concrete');
  if (power) for (let x = power.x * TILE; x < (power.x + power.w) * TILE; x += 8) { R(c, x, (power.y + 2) * TILE + 2, 4, 2, '#c9a02a'); R(c, x + 4, (power.y + 2) * TILE + 2, 4, 2, '#1a1a1a'); }
  interiorWalls(c, loc);
  videoWalls(c, loc);
  for (const f of loc.furniture) FURNITURE[f.type]?.(c, f.x * TILE, f.y * TILE);
  roomLabels(c, loc);
}

const ROOMS = { garage, office, datacentre };

/** Draws one piece of furniture (built-in or bought) with its top-left on tile (tx, ty). */
export function drawFurniture(c, type, tx, ty) {
  FURNITURE[type]?.(c, tx * TILE, ty * TILE);
}
/** Pixel size of a piece of furniture's picture (for the placing ghost). */
export const FURNITURE_PX = { desk: [48, 40], rack: [16, 33] };

export function drawRoom(c, code, loc) {
  c.clearRect(0, 0, COLS * TILE, ROWS * TILE);
  (ROOMS[code] || garage)(c, loc);
}

/** Fan hubs to animate in this location. */
export function fansFor(loc) {
  return loc.furniture.filter((f) => f.type === 'cooling')
    .flatMap((f) => [0, 1].map((i) => ({ x: f.x * TILE + 15, y: f.y * TILE + 11 + i * 20 })));
}

/** Darkens the map edges. Drawn once on top of everything. */
export function vignette(c) {
  const W = COLS * TILE, H = ROWS * TILE;
  const r = Math.max(W, H) * 0.72; // scales with the size of the place
  const g = c.createRadialGradient(W / 2, H * 0.57, r * 0.26, W / 2, H * 0.57, r);
  g.addColorStop(0, 'rgba(0,0,0,0)'); g.addColorStop(1, 'rgba(0,0,0,.45)');
  c.fillStyle = g; c.fillRect(0, 0, W, H);
}

/* ======================= dialogue portraits ======================= */
// Built-in 32x32 pixel portraits, used until staff upload their own art for a character.
export const PORTRAIT_SIZE = 32;

function teacherPortrait(c) {
  const skin = '#e8b48c', skinS = '#c48a66', hair = '#3a2418', hairL = '#5a3a26', card = '#1f7f8f', cardD = '#155a66';
  R(c, 3, 24, 26, 8, card); R(c, 5, 22, 22, 2, card); R(c, 3, 28, 4, 4, cardD); R(c, 25, 28, 4, 4, cardD);
  R(c, 13, 22, 6, 10, '#e9eef2');                                  // blouse
  R(c, 12, 22, 1, 8, C.amber); R(c, 19, 22, 1, 8, C.amber);        // lanyard
  R(c, 14, 27, 4, 3, '#f0f6fa'); R(c, 15, 28, 2, 1, C.cyan);       // badge
  R(c, 14, 19, 4, 4, skinS);                                       // neck
  R(c, 11, 6, 10, 1, skin); R(c, 10, 7, 12, 12, skin); R(c, 11, 19, 10, 1, skinS);
  R(c, 13, 1, 6, 4, hair); R(c, 14, 0, 4, 1, hair);                // bun
  R(c, 9, 4, 14, 4, hair); R(c, 8, 6, 3, 11, hair); R(c, 21, 6, 3, 11, hair);
  R(c, 10, 7, 12, 2, hair); R(c, 12, 5, 5, 1, hairL); R(c, 9, 7, 1, 6, hairL);
  // glasses
  for (const x of [11, 17]) { R(c, x, 11, 4, 1, C.cyan); R(c, x, 14, 4, 1, C.cyan); R(c, x, 11, 1, 4, C.cyan); R(c, x + 3, 11, 1, 4, C.cyan); }
  R(c, 15, 12, 2, 1, C.cyan);
  R(c, 12, 12, 2, 2, '#1a2233'); R(c, 18, 12, 2, 2, '#1a2233'); R(c, 12, 12, 1, 1, '#f0f6fa'); R(c, 18, 12, 1, 1, '#f0f6fa');
  R(c, 11, 16, 1, 1, '#f08a8a'); R(c, 20, 16, 1, 1, '#f08a8a');  // blush
  R(c, 14, 17, 4, 1, '#9e4a4a'); R(c, 13, 16, 1, 1, '#9e4a4a'); R(c, 18, 16, 1, 1, '#9e4a4a'); // smile
}

function hackerPortrait(c) {
  const hood = '#1b1f2e', hoodL = '#2a3044', dark = '#0b0d14', mask = '#05070c';
  R(c, 2, 24, 28, 8, hood); R(c, 5, 21, 22, 3, hood);
  R(c, 8, 3, 16, 20, hood); R(c, 7, 6, 18, 16, hood); R(c, 9, 2, 14, 1, hoodL); R(c, 8, 3, 1, 4, hoodL);
  R(c, 10, 6, 12, 15, dark);                                       // inside the hood
  R(c, 11, 9, 10, 11, mask);
  R(c, 11, 12, 10, 2, C.magenta); R(c, 15, 12, 2, 2, mask);        // visor
  R(c, 12, 12, 2, 1, '#ffb3d6'); R(c, 18, 12, 2, 1, '#ffb3d6');    // glinting eyes
  R(c, 13, 17, 6, 1, '#1a1f2c');                                   // mask seam
  R(c, 13, 21, 1, 4, '#9fb3c8'); R(c, 18, 21, 1, 4, '#9fb3c8');    // drawstrings
  // a little "</>" glowing on the chest
  R(c, 12, 27, 1, 1, C.lime); R(c, 11, 28, 1, 1, C.lime); R(c, 12, 29, 1, 1, C.lime);
  R(c, 16, 26, 1, 1, C.lime); R(c, 15, 28, 1, 1, C.lime); R(c, 14, 30, 1, 1, C.lime);
  R(c, 19, 27, 1, 1, C.lime); R(c, 20, 28, 1, 1, C.lime); R(c, 19, 29, 1, 1, C.lime);
}

function genericPortrait(c) {
  R(c, 4, 24, 24, 8, '#3a4a5e'); R(c, 11, 7, 10, 13, '#c9a27e'); R(c, 10, 5, 12, 4, '#2a2a33'); R(c, 13, 12, 2, 2, '#1a2233'); R(c, 17, 12, 2, 2, '#1a2233');
}

/** Vex, the grey-market broker: long coat with the collar up, shades, a gold chain, a smirk. */
function brokerPortrait(c) {
  const coat = '#6a5a82', coatD = '#433858', skin = '#c98f6b', skinS = '#8f5f42', hair = '#0b0b10';
  R(c, 2, 24, 28, 8, coat); R(c, 5, 21, 22, 3, coat);
  R(c, 6, 18, 5, 8, coatD); R(c, 21, 18, 5, 8, coatD);             // collar up
  R(c, 13, 22, 6, 10, '#17171d');                                  // dark shirt
  for (let x = 12; x <= 19; x++) R(c, x, 23 + (x % 2), 1, 1, C.amber); // gold chain
  R(c, 14, 19, 4, 4, skinS);                                       // neck
  R(c, 11, 6, 10, 1, skin); R(c, 10, 7, 12, 12, skin); R(c, 11, 19, 10, 1, skinS);
  R(c, 9, 3, 14, 5, hair); R(c, 10, 2, 11, 1, hair); R(c, 9, 7, 2, 5, hair); R(c, 21, 7, 2, 4, hair); // slicked back
  R(c, 12, 3, 6, 1, '#2a2a36');
  R(c, 10, 11, 12, 3, '#05070d'); R(c, 11, 11, 4, 2, '#1f2a3a'); R(c, 17, 11, 4, 2, '#1f2a3a'); // shades
  R(c, 12, 11, 1, 1, C.amber); R(c, 18, 11, 1, 1, C.amber);         // a glint
  R(c, 13, 17, 5, 1, '#5e3326'); R(c, 18, 16, 1, 1, '#5e3326');    // smirk
  R(c, 12, 18, 7, 1, skinS);                                       // stubble line
}

/** Mira from Lumen Capital: sharp blazer, bob haircut, earpiece. */
function investorPortrait(c) {
  const suit = '#1d3557', suitD = '#14243d', skin = '#9a6a4a', skinS = '#77503a', hair = '#1a1110';
  R(c, 3, 24, 26, 8, suit); R(c, 5, 22, 22, 2, suit); R(c, 3, 28, 4, 4, suitD); R(c, 25, 28, 4, 4, suitD);
  R(c, 13, 22, 6, 10, '#e9eef2'); R(c, 12, 22, 2, 6, suitD); R(c, 18, 22, 2, 6, suitD); // lapels
  R(c, 21, 26, 3, 1, C.lime);                                      // pocket square
  R(c, 14, 19, 4, 4, skinS);
  R(c, 11, 6, 10, 1, skin); R(c, 10, 7, 12, 12, skin); R(c, 11, 19, 10, 1, skinS);
  R(c, 9, 3, 14, 5, hair); R(c, 8, 6, 3, 12, hair); R(c, 21, 6, 3, 12, hair); R(c, 10, 7, 7, 2, hair); // bob with a side part
  R(c, 12, 4, 4, 1, '#3a2620');
  R(c, 12, 12, 2, 2, '#1a2233'); R(c, 18, 12, 2, 2, '#1a2233'); R(c, 12, 12, 1, 1, '#f0f6fa'); R(c, 18, 12, 1, 1, '#f0f6fa');
  R(c, 12, 11, 3, 1, hair); R(c, 17, 11, 3, 1, hair);              // brows
  R(c, 14, 17, 4, 1, '#7a2e3a');                                   // lipstick
  R(c, 22, 12, 1, 3, C.cyan);                                      // earpiece
}

const PORTRAITS = { teacher: teacherPortrait, hacker: hackerPortrait, broker: brokerPortrait, investor: investorPortrait };

/** Adds built-in portraits for more characters (the story's are in story-art.js). */
export function registerPortraits(more) { Object.assign(PORTRAITS, more); }

/** Draws the built-in portrait for a character code. */
export function portrait(c, code) {
  (PORTRAITS[code] || genericPortrait)(c);
}

/** Fills every <canvas data-portrait="code"> on the page with its built-in portrait, scaled up crisp. */
export function paintPortraits(root = document) {
  root.querySelectorAll('canvas[data-portrait]').forEach((cv) => {
    const scale = Number(cv.dataset.scale || 4);
    cv.width = PORTRAIT_SIZE * scale; cv.height = PORTRAIT_SIZE * scale;
    const ctx = cv.getContext('2d');
    ctx.imageSmoothingEnabled = false;
    ctx.save(); ctx.scale(scale, scale); portrait(ctx, cv.dataset.portrait); ctx.restore();
  });
}
