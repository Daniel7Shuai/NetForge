// The Phaser scene: draws the current location, devices, cables and the player,
// and turns keyboard/pointer input into store actions. It holds no game rules.
import * as layout from './layout.js';
import * as art from './art.js';

const { TILE, COLS, ROWS } = layout;
const W = COLS * TILE, H = ROWS * TILE;
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const LED_COLORS = [0x7cff6b, 0x2be3ff, 0xffb43d];

/** Where a device's sprite is drawn and its visible box, in map pixels. */
export function devicePixel(dev) {
  const s = art.SPRITES[dev.sprite] || art.SPRITES.tower;
  return { x: dev.tile_x * TILE + s.off[0], y: dev.tile_y * TILE + s.off[1], w: s.box[0], h: s.box[1] };
}

/** Where a cable plugs into a device, nudged per port so neighbouring cables don't overlap. */
export function portAnchor(dev, portName) {
  const s = art.SPRITES[dev.sprite] || art.SPRITES.tower;
  const px = devicePixel(dev);
  const i = Math.max(0, (dev.ports || []).findIndex((p) => p.name === portName));
  const spread = Math.min(s.box[0] - 4, 10);
  const nudge = dev.ports && dev.ports.length > 1 ? Math.round((i / (dev.ports.length - 1) - 0.5) * spread) : 0;
  return { x: px.x + s.port[0] + nudge, y: px.y + s.port[1] };
}

/** Cable path: down from each port to a shared floor line, then across. */
export function cableRoute(a, b, devA, devB, lane) {
  const bottom = (d) => (d.tile_y + d.height) * TILE;
  const floorY = Math.max(bottom(devA), bottom(devB)) + 5 + (lane % 4) * 2;
  return [[a.x, a.y], [a.x, floorY], [b.x, floorY], [b.x, b.y]];
}

const STATUS_COLOR = { up: 0x7cff6b, down: 0xff3d9a, negotiating: 0xffb43d };

function alongRoute(pts, f) {
  const segs = [];
  let total = 0;
  for (let i = 1; i < pts.length; i++) { const d = Math.hypot(pts[i][0] - pts[i - 1][0], pts[i][1] - pts[i - 1][1]); segs.push(d); total += d; }
  let s = f * total;
  for (let i = 0; i < segs.length; i++) {
    if (s <= segs[i]) { const k = segs[i] ? s / segs[i] : 0; return [pts[i][0] + (pts[i + 1][0] - pts[i][0]) * k, pts[i][1] + (pts[i + 1][1] - pts[i][1]) * k]; }
    s -= segs[i];
  }
  return pts[pts.length - 1];
}

export class MapScene extends Phaser.Scene {
  constructor(store) {
    super('map');
    this.store = store;
    this.sprites = new Map();
    this.moving = false;
  }

  create() {
    this.makeTextures();
    this.roomImg = this.add.image(0, 0, 'room').setOrigin(0).setDepth(-1000);
    this.slotsG = this.add.graphics().setDepth(0);
    this.cablesG = this.add.graphics().setDepth(1);
    this.ledsG = this.add.graphics().setDepth(5000);
    this.linksG = this.add.graphics().setDepth(5100);
    this.ghost = this.add.image(0, 0, 'tower').setOrigin(0).setDepth(5500).setVisible(false);
    this.hoverG = this.add.graphics().setDepth(6000);
    this.add.image(0, 0, 'vignette').setOrigin(0).setDepth(9000);
    this.fans = art.fansFor(layout.LOC);
    this.store.scene = this;

    const { pos_x, pos_y } = this.store.state.user;
    this.tile = { x: pos_x, y: pos_y };
    this.player = this.add.image(0, 0, 'player').setOrigin(0.5, 1);
    this.placePlayer();

    // no key capture, so typing W/A/S/D into Learn's answer boxes still works
    this.keys = this.input.keyboard.addKeys('W,A,S,D,UP,DOWN,LEFT,RIGHT', false);
    // A quick tap can start and end between two frames; queue it so it still steps.
    const dirs = { w: [0, -1], arrowup: [0, -1], s: [0, 1], arrowdown: [0, 1], a: [-1, 0], arrowleft: [-1, 0], d: [1, 0], arrowright: [1, 0] };
    this.input.keyboard.on('keydown', (e) => {
      if (this.store.state.uiFocus || e.target?.closest?.('input, textarea, select')) return;
      const d = dirs[e.key.toLowerCase()];
      if (d) this.queued = d;
    });
    this.input.on('pointermove', (p) => this.hover(p));
    this.input.on('pointerout', () => this.clearHover());
    this.input.on('pointerdown', (p) => this.click(p));

    this.store.subscribe(() => this.sync());
    this.sync();
  }

  makeTextures() {
    const tex = (key, w, h, draw) => {
      if (this.textures.exists(key)) this.textures.remove(key);
      const t = this.textures.createCanvas(key, w, h);
      draw(t.getContext());
      t.refresh();
    };
    tex('room', W, H, (c) => art.drawRoom(c, layout.LOC_CODE, layout.LOC));
    tex('vignette', W, H, art.vignette);
    tex('player', art.PLAYER_SIZE.w, art.PLAYER_SIZE.h, art.player);
    for (const [name, s] of Object.entries(art.SPRITES)) tex(`dev-${name}`, s.w, s.h, s.draw);
  }

  /* ---------- state -> picture ---------- */

  sync() {
    const { devices } = this.store.state;
    this.blocked = layout.staticBlocked();
    const seen = new Set();
    for (const dev of devices) {
      seen.add(dev.id);
      const px = devicePixel(dev);
      let s = this.sprites.get(dev.id);
      if (!s) {
        s = this.add.image(px.x, px.y, `dev-${dev.sprite}`).setOrigin(0);
        this.sprites.set(dev.id, s);
        if (this.ready && !reduceMotion) {
          // the one placement moment: drop into place
          s.setAlpha(0).setY(px.y - 6);
          this.tweens.add({ targets: s, y: px.y, alpha: 1, duration: 260, ease: 'Expo.easeOut' });
        }
      } else {
        s.setPosition(px.x, px.y);
      }
      s.setDepth(px.y + px.h);
      for (let i = 0; i < dev.height; i++) this.blocked.add(`${dev.tile_x},${dev.tile_y + i}`);
    }
    for (const [id, s] of this.sprites) if (!seen.has(id)) { s.destroy(); this.sprites.delete(id); }
    const sig = devices.map((d) => `${d.id}:${d.tile_x},${d.tile_y}`).join('|');
    if (sig !== this.devSig) { this.devSig = sig; this.redrawRoom(devices); }
    this.drawCables();
    this.drawSlots();
    if (this.lastTile) this.hoverTile(this.lastTile);
    this.ready = true;
  }

  redrawRoom(devices) {
    const t = this.textures.get('room');
    const c = t.getContext();
    art.drawRoom(c, layout.LOC_CODE, layout.LOC);
    // office desks light up their monitor when a PC sits beside them
    for (const d of layout.furnitureOf('desk')) {
      if (devices.some((v) => v.sprite === 'tower' && v.tile_x === d.x + 3 && v.tile_y === d.y)) art.monitorOn(c, d.x, d.y);
    }
    t.refresh();
  }

  /** Works out each cable's route in map pixels from the two devices' current positions. */
  routes() {
    const byId = new Map(this.store.state.devices.map((d) => [d.id, d]));
    return this.store.state.cables.map((cab, lane) => {
      const da = byId.get(cab.a_device_id), db = byId.get(cab.b_device_id);
      if (!da || !db) return null;
      const a = portAnchor(da, cab.a_port), b = portAnchor(db, cab.b_port);
      return { cab, a, b, pts: cableRoute(a, b, da, db, lane) };
    }).filter(Boolean);
  }

  drawCables() {
    const g = this.cablesG;
    g.clear();
    for (const { cab, pts } of this.routes()) {
      const v = pts.map(([x, y]) => new Phaser.Math.Vector2(x + 0.5, y + 0.5));
      const color = Phaser.Display.Color.HexStringToColor(art.CABLE_COLORS[cab.kind] || art.C.cyan).color;
      g.lineStyle(3, 0x000000, 0.55).strokePoints(v);
      if (cab.kind === 'crossover') {
        // dashed, like Packet Tracer's crossover cable
        for (let i = 1; i < v.length; i++) {
          const a = v[i - 1], b = v[i], len = Phaser.Math.Distance.BetweenPoints(a, b);
          for (let s = 0; s < len; s += 5) {
            const t0 = s / len, t1 = Math.min(1, (s + 3) / len);
            g.lineStyle(1, color, 1).lineBetween(a.x + (b.x - a.x) * t0, a.y + (b.y - a.y) * t0, a.x + (b.x - a.x) * t1, a.y + (b.y - a.y) * t1);
          }
        }
      } else {
        g.lineStyle(1, color, cab.status === 'down' ? 0.6 : 1).strokePoints(v);
      }
    }
  }

  /** Link lights at both ends, data pulses on live links, and the cable in hand. */
  drawLinks(t) {
    const g = this.linksG;
    g.clear();
    const fresh = this.store.state.freshCables || {};
    for (const { cab, a, b, pts } of this.routes()) {
      const negotiating = fresh[cab.id] && Date.now() - fresh[cab.id] < 1800;
      const status = negotiating ? 'negotiating' : cab.status;
      const on = status !== 'negotiating' || Math.floor(t * 6) % 2 === 0 || reduceMotion;
      for (const p of [a, b]) {
        g.fillStyle(0x05070d, 1).fillRect(p.x - 1, p.y - 1, 3, 3);
        if (on) g.fillStyle(STATUS_COLOR[status], 1).fillRect(p.x, p.y, 1, 1);
      }
      if (status === 'up' && !reduceMotion) {
        const at = alongRoute(pts, ((t * 0.4) + cab.id * 0.37) % 1);
        g.fillStyle(0xe8fbff, 0.9).fillRect(Math.round(at[0]), Math.round(at[1]), 1, 1);
      }
    }
    const draft = this.store.state.cableDraft;
    if (draft && this.pointer) {
      const dev = this.store.state.devices.find((d) => d.id === draft.from.deviceId);
      if (dev) {
        const a = portAnchor(dev, draft.from.port);
        const color = Phaser.Display.Color.HexStringToColor(art.CABLE_COLORS[draft.kind] || art.C.cyan).color;
        const pts = [[a.x, a.y], [a.x, this.pointer.y], [this.pointer.x, this.pointer.y]];
        const off = reduceMotion ? 0 : (t * 10) % 5;
        for (let i = 1; i < pts.length; i++) {
          const [x0, y0] = pts[i - 1], [x1, y1] = pts[i], len = Math.hypot(x1 - x0, y1 - y0) || 1;
          for (let s = -off; s < len; s += 5) {
            const t0 = Math.max(0, s) / len, t1 = Math.min(1, (s + 3) / len);
            if (t1 > t0) g.lineStyle(1, color, 0.9).lineBetween(x0 + (x1 - x0) * t0 + 0.5, y0 + (y1 - y0) * t0 + 0.5, x0 + (x1 - x0) * t1 + 0.5, y0 + (y1 - y0) * t1 + 0.5);
          }
        }
        g.fillStyle(color, 1).fillRect(a.x, a.y, 1, 1);
      }
    }
  }

  /* ---------- per-frame ---------- */

  update(time) {
    this.movePlayer();
    this.drawLinks(time / 1000);
    if (!this.nextLed || time > this.nextLed) {
      this.nextLed = time + 140;
      this.drawLeds(time / 1000);
      if (this.store.placingItem()) this.drawSlots();
    }
  }

  drawLeds(t) {
    const g = this.ledsG;
    g.clear();
    for (const dev of this.store.state.devices) {
      const spr = art.SPRITES[dev.sprite];
      if (!spr || !spr.leds.length) continue;
      const { x, y } = devicePixel(dev);
      const sx = x - 0, sy = y - 0;
      spr.leds.forEach(([lx, ly], i) => {
        const h = art.hash(dev.id * 7 + i, 3);
        if (spr.leds.length > 4 && h < 0.3) return;
        const on = reduceMotion || Math.sin(t * (2 + h * 6) + h * 40) > -0.2;
        if (!on) return;
        const color = spr.leds.length > 4 ? LED_COLORS[h > 0.8 ? 2 : h > 0.55 ? 1 : 0] : (i === 0 ? LED_COLORS[0] : LED_COLORS[1]);
        g.fillStyle(color, 1).fillRect(sx + lx, sy + ly, 1, 1);
      });
    }
    for (const f of this.fans) {
      const a0 = reduceMotion ? 0 : t * 4;
      g.lineStyle(1, 0x33446a, 1);
      for (let b = 0; b < 3; b++) {
        const a = a0 + b * 2.094;
        g.lineBetween(f.x, f.y, f.x + Math.cos(a) * 7, f.y + Math.sin(a) * 7);
      }
    }
  }

  /* ---------- player ---------- */

  placePlayer() {
    this.player.setPosition(this.tile.x * TILE + 8, this.tile.y * TILE + 15).setDepth(this.tile.y * TILE + 16);
  }

  movePlayer() {
    if (this.moving || this.store.state.uiFocus) return;
    const k = this.keys;
    let dx = 0, dy = 0;
    if (k.A.isDown || k.LEFT.isDown) dx = -1;
    else if (k.D.isDown || k.RIGHT.isDown) dx = 1;
    else if (k.W.isDown || k.UP.isDown) dy = -1;
    else if (k.S.isDown || k.DOWN.isDown) dy = 1;
    else if (this.queued) [dx, dy] = this.queued;
    this.queued = null;
    if (!dx && !dy) return;
    if (dx) this.player.setFlipX(dx < 0);
    const nx = this.tile.x + dx, ny = this.tile.y + dy;
    if (nx < 0 || ny < 0 || nx >= COLS || ny >= ROWS || this.blocked.has(`${nx},${ny}`)) return;
    this.tile = { x: nx, y: ny };
    this.moving = true;
    this.tweens.add({
      targets: this.player,
      x: nx * TILE + 8, y: ny * TILE + 15,
      duration: reduceMotion ? 0 : 130,
      onComplete: () => { this.moving = false; this.player.setDepth(ny * TILE + 16); this.store.playerMoved(this.tile); },
    });
  }

  /* ---------- pointer ---------- */

  tileAt(p) {
    return { x: Math.floor(p.worldX / TILE), y: Math.floor(p.worldY / TILE) };
  }

  deviceAt(tile) {
    return this.store.state.devices.find((d) => d.tile_x === tile.x && tile.y >= d.tile_y && tile.y < d.tile_y + d.height) || null;
  }

  hover(p) {
    this.pointer = { x: Math.floor(p.worldX), y: Math.floor(p.worldY) };
    this.hoverTile(this.tileAt(p));
  }

  clearHover() {
    this.lastTile = null;
    this.hoverG.clear();
    this.ghost.setVisible(false);
  }

  static brackets(g, box, color, alpha = 1) {
    const x = box.x - 2, y = box.y - 2, w = box.w + 4, h = box.h + 4;
    g.fillStyle(color, alpha);
    for (const [rx, ry, rw, rh] of [[x, y, 4, 1], [x, y, 1, 4], [x + w - 4, y, 4, 1], [x + w - 1, y, 1, 4],
      [x, y + h - 1, 4, 1], [x, y + h - 4, 1, 4], [x + w - 4, y + h - 1, 4, 1], [x + w - 1, y + h - 4, 1, 4]]) g.fillRect(rx, ry, rw, rh);
  }

  /** Hover highlight for a tile; also used by the HUD during drag-and-drop. */
  hoverTile(tile) {
    this.lastTile = tile;
    const g = this.hoverG;
    g.clear();
    const item = this.store.placingItem();
    if (item) {
      const { slot, problem } = this.store.placementCheck(item, tile);
      const at = slot || { x: tile.x, y: tile.y };
      const px = devicePixel({ sprite: item.sprite, tile_x: at.x, tile_y: at.y });
      this.ghost.setTexture(`dev-${item.sprite}`).setPosition(px.x, px.y).setVisible(true)
        .setAlpha(problem ? 0.45 : 0.75).setTint(problem ? 0xff3d9a : 0xffffff);
      MapScene.brackets(g, px, problem ? 0xff3d9a : 0x7cff6b);
      return;
    }
    this.ghost.setVisible(false);
    const dev = this.deviceAt(tile);
    const box = dev ? devicePixel(dev) : { x: tile.x * TILE, y: tile.y * TILE, w: 16, h: 16 };
    MapScene.brackets(g, box, dev ? 0x2be3ff : 0x8aa3bb, dev ? 1 : 0.6);
  }

  /** While a device is selected, outline every free spot it could go. */
  drawSlots() {
    const g = this.slotsG;
    g.clear();
    const item = this.store.placingItem();
    if (!item) return;
    const t = this.time.now / 1000;
    for (const slot of layout.slotsFor(item.slotKinds)) {
      if (this.store.occupant(slot)) continue;
      const px = devicePixel({ sprite: item.sprite, tile_x: slot.x, tile_y: slot.y });
      g.fillStyle(0x2be3ff, 0.1).fillRect(px.x - 1, px.y - 1, px.w + 2, px.h + 2);
      MapScene.brackets(g, px, 0x2be3ff, reduceMotion ? 0.8 : 0.55 + 0.3 * Math.sin(t * 3));
    }
  }

  click(p) {
    const tile = this.tileAt(p);
    this.store.tileClicked(tile, this.deviceAt(tile));
  }
}
