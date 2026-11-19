// Landing page hero: the actual game's garage, drawn with the game's own pixel art,
// set up the way a new player builds it: laptop, home router, cables up to the ISP box.
import * as art from './game/art.js';
import { TILE, COLS, ROWS, setLocation, LOC } from './game/layout.js';

setLocation('garage');
const cv = document.getElementById('hero-art');
const ctx = cv.getContext('2d');
const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

// pre-render the static room once
const room = document.createElement('canvas');
room.width = COLS * TILE;
room.height = ROWS * TILE;
art.drawRoom(room.getContext('2d'), 'garage', LOC);

const sprite = (name) => {
  const s = art.SPRITES[name];
  const c = document.createElement('canvas');
  c.width = s.w; c.height = s.h;
  s.draw(c.getContext('2d'));
  return c;
};
const at = (name, tx, ty) => {
  const s = art.SPRITES[name];
  return { name, x: tx * TILE + s.off[0], y: ty * TILE + s.off[1], s, img: sprite(name) };
};
const devices = [at('ispbox', 10, 1), at('laptop', 2, 2), at('homerouter', 6, 2)];
const port = (d, dx = 0) => ({ x: d.x + d.s.port[0] + dx, y: d.y + d.s.port[1] });
const [isp, laptop, router] = devices;
// same routing as the game: down from each port to a floor line, then across
const route = (a, b, y) => [[a.x, a.y], [a.x, y], [b.x, y], [b.x, b.y]];
const cables = [
  route(port(isp), port(router, -3), 54),
  route(port(laptop), port(router, 3), 56),
];

const playerImg = document.createElement('canvas');
playerImg.width = art.PLAYER_SIZE.w; playerImg.height = art.PLAYER_SIZE.h;
art.player(playerImg.getContext('2d'));
const vignette = document.createElement('canvas');
vignette.width = room.width; vignette.height = room.height;
art.vignette(vignette.getContext('2d'));

function along(pts, f) {
  const seg = [];
  let total = 0;
  for (let i = 1; i < pts.length; i++) { const d = Math.abs(pts[i][0] - pts[i - 1][0]) + Math.abs(pts[i][1] - pts[i - 1][1]); seg.push(d); total += d; }
  let s = f * total;
  for (let i = 0; i < seg.length; i++) {
    if (s <= seg[i]) { const k = seg[i] ? s / seg[i] : 0; return [pts[i][0] + (pts[i + 1][0] - pts[i][0]) * k, pts[i][1] + (pts[i + 1][1] - pts[i][1]) * k]; }
    s -= seg[i];
  }
  return pts[pts.length - 1];
}

function frame(ms) {
  const t = ms / 1000;
  ctx.drawImage(room, 0, 0);
  // cables under the devices
  for (const pts of cables) {
    ctx.lineWidth = 3; ctx.strokeStyle = 'rgba(0,0,0,.55)';
    ctx.beginPath(); pts.forEach(([x, y], i) => (i ? ctx.lineTo(x + 0.5, y + 0.5) : ctx.moveTo(x + 0.5, y + 0.5))); ctx.stroke();
    ctx.lineWidth = 1; ctx.strokeStyle = art.C.cyan; ctx.stroke();
  }
  for (const d of devices) ctx.drawImage(d.img, d.x, d.y);
  // blinking device lights
  for (const d of devices) {
    d.s.leds.forEach(([lx, ly], i) => {
      if (reduce || Math.sin(t * (2 + i * 1.7) + i * 3) > -0.3) { ctx.fillStyle = i ? art.C.cyan : art.C.lime; ctx.fillRect(d.x + lx, d.y + ly, 1, 1); }
    });
  }
  // link lights and data pulses
  for (const pts of cables) {
    for (const p of [pts[0], pts[pts.length - 1]]) { ctx.fillStyle = art.C.lime; ctx.fillRect(p[0], p[1], 1, 1); }
    if (!reduce) { const [x, y] = along(pts, (t * 0.35) % 1); ctx.fillStyle = '#e8fbff'; ctx.fillRect(Math.round(x), Math.round(y), 1, 1); }
  }
  ctx.drawImage(playerImg, 6 * TILE + 2, 4 * TILE - 2);
  ctx.drawImage(vignette, 0, 0);
  if (!reduce) requestAnimationFrame(frame);
}
requestAnimationFrame(frame);
