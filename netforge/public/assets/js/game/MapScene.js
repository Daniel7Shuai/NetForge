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
    // the player: point-and-click, with idle (breathing) and walking animations
    this.anims.create({ key: 'idle', frames: [{ key: 'player-idle0' }, { key: 'player-idle1' }], frameRate: 2, repeat: -1 });
    this.anims.create({ key: 'walk', frames: [{ key: 'player-walk1' }, { key: 'player-walk0' }, { key: 'player-walk2' }, { key: 'player-walk0' }], frameRate: 9, repeat: -1 });
    this.player = this.add.sprite(0, 0, 'player-idle0').setOrigin(0.5, 1);
    if (!reduceMotion) this.player.play('idle');
    this.walkId = 0;
    this.placePlayer();

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
    for (const pose of art.PLAYER_FRAMES) {
      tex(`player-${pose}`, art.PLAYER_SIZE.w, art.PLAYER_SIZE.h, (c) => art.playerFrame(c, this.store.state.user?.avatar, pose));
    }
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

  walkable(x, y) {
    return x >= 0 && y >= 0 && x < COLS && y < ROWS && !this.blocked.has(`${x},${y}`);
  }

  /** Shortest path (4 directions) from the player to any of the goal tiles, or null. */
  pathTo(goals) {
    const want = new Set(goals.filter((g) => this.walkable(g.x, g.y)).map((g) => `${g.x},${g.y}`));
    if (!want.size) return null;
    const start = `${this.tile.x},${this.tile.y}`;
    if (want.has(start)) return [];
    const prev = new Map([[start, null]]);
    const queue = [this.tile];
    while (queue.length) {
      const cur = queue.shift();
      for (const [dx, dy] of [[0, 1], [0, -1], [1, 0], [-1, 0]]) {
        const nx = cur.x + dx, ny = cur.y + dy, key = `${nx},${ny}`;
        if (prev.has(key) || !this.walkable(nx, ny)) continue;
        prev.set(key, `${cur.x},${cur.y}`);
        if (want.has(key)) {
          const path = [];
          for (let k = key; k !== start; k = prev.get(k)) { const [x, y] = k.split(',').map(Number); path.unshift({ x, y }); }
          return path;
        }
        queue.push({ x: nx, y: ny });
      }
    }
    return null;
  }

  /**
   * Walks the player to the nearest of the goal tiles. Resolves true on arrival, false if
   * there's no way there or another walk replaced this one.
   */
  walkTo(goals) {
    const path = this.pathTo(Array.isArray(goals) ? goals : [goals]);
    if (path === null) return Promise.resolve(false);
    const id = ++this.walkId;
    this.tweens.killTweensOf(this.player);
    if (!path.length) { this.stopWalking(); return Promise.resolve(true); }
    if (!reduceMotion) this.player.play('walk', true);
    return new Promise((resolve) => {
      const step = (i) => {
        if (id !== this.walkId) { resolve(false); return; }       // a newer click took over
        if (i >= path.length) { this.stopWalking(); this.store.playerMoved(this.tile); resolve(true); return; }
        const t = path[i];
        if (t.x !== this.tile.x) this.player.setFlipX(t.x < this.tile.x);
        this.tile = t;
        this.tweens.add({
          targets: this.player, x: t.x * TILE + 8, y: t.y * TILE + 15, duration: reduceMotion ? 0 : 120,
          onUpdate: () => this.player.setDepth(this.player.y + 1),
          onComplete: () => step(i + 1),
        });
      };
      step(0);
    });
  }

  stopWalking() {
    if (reduceMotion) { this.player.setTexture('player-idle0'); return; }
    this.player.play('idle', true);
    this.player.setDepth(this.tile.y * TILE + 16);
  }

  /** Walks up to a slot (in front of it if possible) so the player can set something down there. */
  walkNear(slot) {
    const h = slot.h || 1, around = [];
    for (let y = slot.y; y < slot.y + h; y++) around.push({ x: slot.x - 1, y }, { x: slot.x + 1, y });
    // in front (below) first, then the sides, then behind
    const goals = [{ x: slot.x, y: slot.y + h }, ...around, { x: slot.x, y: slot.y - 1 }];
    return this.walkTo(goals);
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
    if (this.store.state.uiFocus) return;
    const tile = this.tileAt(p);
    const device = this.deviceAt(tile);
    // point and click: open floor with nothing in hand means "walk there"
    if (!device && !this.store.placingItem() && this.store.state.tool === 'select' && !this.store.state.cableDraft) {
      this.store.set({ picker: null });
      this.walkTo(tile).then((ok) => { if (!ok && !this.walkable(tile.x, tile.y)) this.flashBlocked(tile); });
      return;
    }
    this.store.tileClicked(tile, device);
  }

  /** A quick cross on a tile the player can't walk to. */
  flashBlocked(tile) {
    const g = this.add.graphics().setDepth(6100);
    const x = tile.x * TILE, y = tile.y * TILE;
    g.lineStyle(1, 0xff3d9a, 0.9).lineBetween(x + 4, y + 4, x + 12, y + 12).lineBetween(x + 12, y + 4, x + 4, y + 12);
    this.tweens.add({ targets: g, alpha: 0, duration: reduceMotion ? 0 : 450, delay: 150, onComplete: () => g.destroy() });
  }
}
