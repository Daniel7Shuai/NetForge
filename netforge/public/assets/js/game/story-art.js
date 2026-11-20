// Pixel art for the story: Dad, Lucas, DataTech's Reapers, the VTOL dropship, the breached wall
// and DataTech's prison. Same rules as art.js: drawn in code, 1 logical pixel = 1 canvas pixel.
import { C, NPCS, registerPortraits } from './art.js';

const R = (c, x, y, w, h, col) => { c.fillStyle = col; c.fillRect(Math.round(x), Math.round(y), w, h); };
export const DT_RED = '#d42a3a';

/* ---------- walking characters (palettes for playerFrame) ---------- */
Object.assign(NPCS, {
  dad:    { h: '#cfcac2', s: '#e0a582', S: '#a8714f', j: '#3e4452', J: '#2a2e38', c: '#e8eef4', a: '#8a1f2e', n: '#2a2e38', b: '#0d1018' },
  lucas:  { h: '#d9b44a', s: '#e8b48c', S: '#b07c5a', j: '#16161c', J: '#0b0b10', c: DT_RED, a: DT_RED, n: '#16161c', b: '#050508' },
  // DataTech's Reapers: full armour, a faceplate for a face and a red visor slit for eyes
  reaper: { h: '#22252c', s: '#30343c', S: '#1c1f25', w: '#ff2a3a', j: '#3b4048', J: '#262a31', c: '#ff5a1f', a: '#ff5a1f', n: '#22252c', b: '#111317' },
  ada:    { h: '#3a2418', s: '#e8b48c', S: '#c48a66', j: '#1f7f8f', J: '#155a66', c: C.cyan, a: C.amber },
  staff1: { h: '#121212', j: '#4a5a6e', J: '#2c3846', c: '#9fb3c8', a: C.amber },
  staff2: { h: '#7a2d17', j: '#2b8a74', J: '#195446', c: '#6ff0c8', a: C.magenta },
  staff3: { h: '#5a3a1e', j: '#5b3c9e', J: '#372361', c: '#b494ff', a: C.lime },
});

/* ---------- portraits (32x32) ---------- */
function dadPortrait(c) {
  const suit = '#3e4452', suitD = '#2a2e38', skin = '#e0a582', skinS = '#b07c5a', hair = '#cfcac2', hairD = '#9c968d';
  R(c, 2, 24, 28, 8, suit); R(c, 5, 22, 22, 2, suit); R(c, 2, 28, 5, 4, suitD); R(c, 25, 28, 5, 4, suitD);
  R(c, 13, 22, 6, 10, '#e8eef4'); R(c, 15, 23, 2, 9, '#8a1f2e'); R(c, 14, 23, 4, 1, '#6a1622'); // shirt and tie
  R(c, 12, 22, 2, 6, suitD); R(c, 18, 22, 2, 6, suitD);                                         // lapels
  R(c, 14, 19, 4, 4, skinS);
  R(c, 11, 6, 10, 1, skin); R(c, 10, 7, 12, 12, skin); R(c, 11, 19, 10, 1, skinS);
  R(c, 10, 3, 12, 4, hair); R(c, 9, 5, 2, 6, hair); R(c, 21, 5, 2, 6, hair); R(c, 12, 2, 8, 1, hairD); // swept-back grey hair
  R(c, 12, 11, 3, 1, hairD); R(c, 17, 11, 3, 1, hairD);                                         // brows
  R(c, 12, 12, 2, 2, '#1a2233'); R(c, 18, 12, 2, 2, '#1a2233'); R(c, 12, 12, 1, 1, '#f0f6fa'); R(c, 18, 12, 1, 1, '#f0f6fa');
  R(c, 13, 16, 6, 1, '#8a5a44'); R(c, 12, 17, 8, 1, hairD); R(c, 13, 18, 6, 1, hairD);         // moustache and short beard
  R(c, 10, 14, 1, 2, skinS); R(c, 21, 14, 1, 2, skinS);
}

function lucasPortrait(c) {
  const suit = '#16161c', skin = '#e8b48c', skinS = '#b07c5a', hair = '#d9b44a', hairD = '#a8862e';
  R(c, 2, 24, 28, 8, suit); R(c, 5, 22, 22, 2, suit);
  R(c, 13, 22, 6, 10, '#2a2a33'); R(c, 15, 23, 2, 9, DT_RED);                                   // dark shirt, red tie
  R(c, 4, 26, 4, 2, DT_RED); R(c, 5, 25, 2, 4, DT_RED);                                         // DataTech pin
  R(c, 14, 19, 4, 4, skinS);
  R(c, 11, 6, 10, 1, skin); R(c, 10, 7, 12, 12, skin); R(c, 11, 19, 10, 1, skinS);
  R(c, 9, 2, 14, 5, hair); R(c, 9, 6, 2, 3, hair); R(c, 18, 3, 6, 2, hairD); R(c, 12, 1, 10, 1, hair); // slicked back, parted
  R(c, 12, 11, 3, 1, hairD); R(c, 17, 10, 3, 1, hairD);                                         // one brow raised
  R(c, 12, 12, 2, 2, '#3a5a8a'); R(c, 18, 12, 2, 2, '#3a5a8a'); R(c, 12, 12, 1, 1, '#f0f6fa'); R(c, 18, 12, 1, 1, '#f0f6fa');
  R(c, 14, 17, 5, 1, '#8a4a3a'); R(c, 19, 16, 1, 1, '#8a4a3a');                                // smirk
  R(c, 22, 12, 1, 3, '#2a2a33');                                                                // earpiece
}

function reaperPortrait(c) {
  const armour = '#3b4048', armourD = '#262a31', helm = '#22252c', plate = '#30343c';
  R(c, 1, 23, 30, 9, armour); R(c, 3, 21, 26, 3, armourD);
  R(c, 1, 23, 7, 6, armourD); R(c, 24, 23, 7, 6, armourD);                                      // shoulder plates
  R(c, 2, 24, 5, 1, '#ff5a1f'); R(c, 25, 24, 5, 1, '#ff5a1f');                                   // orange trim
  R(c, 13, 26, 6, 4, '#1c1f25'); R(c, 15, 27, 2, 2, DT_RED);                                    // chest light
  R(c, 9, 3, 14, 18, helm); R(c, 8, 6, 16, 13, helm); R(c, 10, 2, 12, 1, '#2e323a');           // helmet
  R(c, 10, 8, 12, 11, plate);                                                                   // faceplate
  R(c, 10, 11, 12, 2, '#ff2a3a'); R(c, 10, 11, 12, 1, '#ff8a94');                               // visor slit
  for (let i = 0; i < 4; i++) R(c, 12 + i * 2, 16, 1, 2, '#1c1f25');                            // vents
  R(c, 8, 9, 1, 6, '#ff5a1f');
}

registerPortraits({ dad: dadPortrait, lucas: lucasPortrait, reaper: reaperPortrait });

/* ---------- DataTech's VTOL dropship, flying left: 96 x 44 ---------- */
export const SHIP = { w: 96, h: 44 };
export function dropship(c, x, y, t = 0, door = 0) {
  const hull = '#2b2f38', hullL = '#3c424e', hullD = '#1a1d23';
  // thruster wash
  const flick = (Math.sin(t * 40) + 1) / 2;
  for (const nx of [18, 66]) {
    R(c, x + nx + 2, y + 36, 8, 2 + Math.round(flick * 3), 'rgba(43,227,255,.55)');
    R(c, x + nx + 4, y + 38, 4, 2 + Math.round(flick * 4), 'rgba(232,251,255,.8)');
  }
  // body: a wedge with the cockpit at the front (left)
  for (let j = 0; j < 22; j++) {
    const inset = Math.max(0, 14 - j);
    R(c, x + 4 + inset, y + 10 + j, 84 - inset, 1, j < 3 ? hullL : j > 18 ? hullD : hull);
  }
  R(c, x + 30, y + 4, 46, 6, hull); R(c, x + 30, y + 4, 46, 1, hullL);                    // dorsal spine
  R(c, x + 70, y, 10, 5, hullD);                                                          // tail fin
  R(c, x + 10, y + 12, 16, 7, '#3a2a10'); R(c, x + 12, y + 13, 12, 4, C.amber); R(c, x + 12, y + 13, 5, 1, '#ffe2a8'); // cockpit glow
  R(c, x + 28, y + 22, 58, 2, DT_RED);                                                    // red stripe
  // DT on the flank
  for (const [dx, dy] of [[0, 0], [0, 1], [0, 2], [0, 3], [1, 0], [2, 1], [2, 2], [1, 3], [4, 0], [5, 0], [6, 0], [5, 1], [5, 2], [5, 3]]) R(c, x + 74 + dx, y + 14 + dy, 1, 1, '#c9cdd6');
  // side door: slides open
  const dw = 22, open = Math.round(dw * door);
  R(c, x + 40, y + 12, dw, 9, hullD);
  if (open) R(c, x + 40, y + 12, open, 9, '#05070d');
  if (open) R(c, x + 40, y + 20, open, 1, 'rgba(255,42,58,.6)');
  // nacelles
  for (const nx of [18, 66]) { R(c, x + nx, y + 30, 12, 6, hullD); R(c, x + nx + 1, y + 30, 10, 1, hullL); }
  // running lights
  const blink = Math.floor(t * 3) % 2 === 0;
  R(c, x + 4, y + 20, 2, 2, blink ? '#ff2a3a' : '#5a1018'); R(c, x + 86, y + 12, 2, 2, blink ? '#5a1018' : '#ff2a3a');
}

/* ---------- the hole the dropship makes in the wall ---------- */
export function wallBreach(c, x, y, w, h, seed = 7) {
  const rnd = (i) => { const v = Math.sin(seed * 91.7 + i * 12.9898) * 43758.5453; return v - Math.floor(v); };
  c.fillStyle = '#05070d';
  c.beginPath();
  const n = 18;
  for (let i = 0; i < n; i++) {
    const a = (i / n) * Math.PI * 2;
    const r = 0.78 + rnd(i) * 0.32;
    c.lineTo(x + w / 2 + Math.cos(a) * (w / 2) * r, y + h / 2 + Math.sin(a) * (h / 2) * r);
  }
  c.closePath(); c.fill();
  // the night outside, and a glow from the city
  c.save(); c.clip();
  R(c, x, y, w, h, '#070b18');
  for (let i = 0; i < 14; i++) R(c, x + rnd(i + 30) * w, y + rnd(i + 60) * h * 0.6, 1, 1, rnd(i) > 0.5 ? '#3a4a7a' : '#ffb43d');
  R(c, x, y + h * 0.7, w, h * 0.3, 'rgba(255,90,31,.12)');
  c.restore();
  // broken edges
  c.strokeStyle = '#3a4d6e'; c.lineWidth = 1;
  c.stroke();
}

/* ---------- DataTech's logo ---------- */
export function datatechLogo(c, x, y, s = 1) {
  // a red hexagon with a white D and T
  const hex = [[4, 0], [12, 0], [16, 7], [12, 14], [4, 14], [0, 7]];
  c.fillStyle = DT_RED;
  c.beginPath(); hex.forEach(([hx, hy], i) => (i ? c.lineTo : c.moveTo).call(c, x + hx * s, y + hy * s)); c.closePath(); c.fill();
  const px = (dx, dy) => R(c, x + dx * s, y + dy * s, s, s, '#f4f6fa');
  for (let j = 3; j <= 11; j++) px(4, j);
  for (const [dx, dy] of [[5, 3], [6, 4], [7, 5], [7, 6], [7, 7], [7, 8], [7, 9], [6, 10], [5, 11]]) px(dx, dy);
  for (let i = 9; i <= 13; i++) px(i, 3);
  for (let j = 4; j <= 11; j++) px(11, j);
}

/** 16x16 shop icon for buying DataTech. */
export function datatechIcon(c) { datatechLogo(c, 0, 1, 1); }

/* ---------- DataTech's prison: 36 x 20 tiles, like the datacentre ---------- */
export function prisonRoom(c, W, H, t = 0) {
  R(c, 0, 0, W, H, '#0b0d12');
  // back wall with cells
  R(c, 0, 0, W, 96, '#151820'); R(c, 0, 94, W, 2, '#05070d');
  for (let x = 0; x < W; x += 24) R(c, x, 0, 1, 94, '#10131a');
  // floor: polished concrete, a red line to the cells
  for (let y = 96; y < H; y += 16) for (let x = 0; x < W; x += 16) R(c, x, y, 16, 16, ((x + y) / 16) % 2 ? '#14171e' : '#161a22');
  for (let x = 0; x < W; x += 6) R(c, x, 212, 3, 1, '#5a1822');
  // red alarm lights pulsing along the top
  const pulse = 0.25 + 0.2 * Math.sin(t * 3);
  for (let x = 40; x < W; x += 120) { R(c, x, 6, 8, 3, DT_RED); R(c, x - 12, 9, 32, 26, `rgba(212,42,58,${pulse.toFixed(2)})`); }
  // logo on the wall
  datatechLogo(c, W / 2 - 16, 20, 2);
  // the cells
  for (const cx of [96, 240, 384]) {
    R(c, cx, 44, 96, 50, '#07090d');
    R(c, cx + 8, 60, 20, 6, '#1b1f27'); R(c, cx + 8, 66, 20, 1, '#0b0d12'); // a bunk
  }
}

/** Cell bars across a cell; open is 0..1 (they slide up). */
export function cellBars(c, x, y, w, h, open = 0) {
  const lift = Math.round(h * open);
  R(c, x - 2, y - 3, w + 4, 3, '#3a4d6e');
  for (let bx = x + 2; bx < x + w; bx += 6) R(c, bx, y, 2, h - lift, '#5a6b84');
  R(c, x, y + h - lift - 2, w, 2, '#5a6b84');
}

export function heart(c, x, y, col = '#ff3d9a') {
  for (const [dx, dy, w] of [[1, 0, 2], [4, 0, 2], [0, 1, 7], [0, 2, 7], [1, 3, 5], [2, 4, 3], [3, 5, 1]]) R(c, x + dx, y + dy, w, 1, col);
}
