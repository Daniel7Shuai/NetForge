// Floor plans for every location (garage, office, ...). The data lives in
// assets/data/layout.json, which the PHP rules read too, so the client and
// server always agree on valid spots. Call setLocation() once at boot.

const L = await (await fetch(new URL('../../data/layout.json', import.meta.url))).json(); // relative to this file, so any page can import it

export const TILE = L.tile;
/** Size of the place on screen, in tiles. Bigger places are bigger; set by setLocation(). */
export let COLS = L.cols;
export let ROWS = L.rows;
export const FOOTPRINTS = L.footprints;
export const LOCATIONS = L.locations;

/** The location currently on screen. */
export let LOC = null;
export let LOC_CODE = null;

export function setLocation(code) {
  LOC_CODE = LOCATIONS[code] ? code : 'garage';
  LOC = LOCATIONS[LOC_CODE];
  COLS = LOC.cols || L.cols;
  ROWS = LOC.rows || L.rows;
}

const key = (x, y) => `${x},${y}`;

/** Furniture the student bought and placed here: [{id, type, item, x, y}]. Set from the game state. */
export let USER_FURNITURE = [];
export function setFurniture(list) { USER_FURNITURE = list || []; }

/** The spot a piece of bought furniture adds. Mirrors NetForge\Layout::furnitureSlot(). */
export function furnitureSlot(type, x, y) {
  if (type === 'desk') return { kind: 'desk', x: x + 3, y, h: 1 }; // the PC stands at the desk's right end
  if (type === 'rack') return { kind: 'rack', x, y, h: 2 };        // the device goes in the rack itself
  return null;
}

/** Every tile a piece of furniture needs: its footprint plus the spot it adds. Mirrors Gear::tilesFor(). */
export function furnitureTiles(type, x, y) {
  const [w, h] = FOOTPRINTS[type] || [1, 1];
  const tiles = [];
  for (let i = 0; i < w; i++) for (let j = 0; j < h; j++) tiles.push([x + i, y + j]);
  const s = furnitureSlot(type, x, y);
  if (s && !tiles.some(([tx, ty]) => tx === s.x && ty === s.y)) for (let j = 0; j < s.h; j++) tiles.push([s.x, s.y + j]);
  return tiles;
}

/** Why a piece of furniture can't stand with its top-left at (x, y), or null. Mirrors Gear::placeFurniture(). */
export function furnitureProblem(type, x, y, devices, player) {
  const b = staticBlocked();
  for (const s of LOC.slots) for (let j = 0; j < s.h; j++) b.add(key(s.x, s.y + j)); // built-in spots stay clear
  for (const f of USER_FURNITURE) for (const [tx, ty] of furnitureTiles(f.type, f.x, f.y)) b.add(key(tx, ty));
  for (const d of devices) for (let j = 0; j < d.height; j++) b.add(key(d.tile_x, d.tile_y + j));
  for (const [tx, ty] of furnitureTiles(type, x, y)) {
    if (tx < 0 || ty < 0 || tx >= COLS || ty >= ROWS || b.has(key(tx, ty))) return 'There isn’t enough open floor there. Pick an empty area.';
    if (player && player.x === tx && player.y === ty) return 'Step out of the way first.';
  }
  return null;
}

/** Interior walls between rooms, less their doorways. Mirrors Layout::wallTiles(). */
export function wallTiles() {
  const out = new Set();
  for (const w of LOC.walls || []) {
    const doors = new Set((w.doors || []).map(([x, y]) => key(x, y)));
    for (let i = 0; i < w.w; i++) for (let j = 0; j < w.h; j++) {
      const k = key(w.x + i, w.y + j);
      if (!doors.has(k)) out.add(k);
    }
  }
  return out;
}

/** Tiles the player cannot walk through, ignoring placed devices. */
export function staticBlocked() {
  const b = new Set();
  for (let x = 0; x < COLS; x++) for (let y = 0; y < LOC.wallRows; y++) b.add(key(x, y));
  if (LOC.bottomWall) for (let x = 0; x < COLS; x++) b.add(key(x, ROWS - 1));
  if (LOC.partition) {
    for (let y = LOC.wallRows; y < ROWS; y++) if (!LOC.partition.doorRows.includes(y)) b.add(key(LOC.partition.col, y));
  }
  for (const t of wallTiles()) b.add(t);
  for (const f of LOC.furniture) {
    const [w, h] = FOOTPRINTS[f.type] || [1, 1];
    for (let i = 0; i < w; i++) for (let j = 0; j < h; j++) b.add(key(f.x + i, f.y + j));
  }
  for (const f of USER_FURNITURE) {
    const [w, h] = FOOTPRINTS[f.type] || [1, 1];
    for (let i = 0; i < w; i++) for (let j = 0; j < h; j++) b.add(key(f.x + i, f.y + j));
  }
  return b;
}

/** Is a tile open floor (not a wall, furniture, a built-in spot or bought furniture)? Mirrors Layout::openFloor(). */
export function openFloor(x, y) {
  if (x < 0 || y < 0 || x >= COLS || y >= ROWS) return false;
  const b = staticBlocked();
  for (const s of LOC.slots) for (let j = 0; j < s.h; j++) b.add(key(s.x, s.y + j));
  for (const f of USER_FURNITURE) for (const [tx, ty] of furnitureTiles(f.type, f.x, f.y)) b.add(key(tx, ty));
  return !b.has(key(x, y));
}

/** Spots in this location that accept any of the given slot kinds. Mirrors NetForge\Layout::slotsFor(). */
export function slotsFor(kinds) {
  const list = Array.isArray(kinds) ? kinds : String(kinds || '').split(',');
  const mine = USER_FURNITURE.map((f) => furnitureSlot(f.type, f.x, f.y)).filter(Boolean);
  return [...LOC.slots, ...mine].filter((s) => list.includes(s.kind));
}

/** The slot covering a tile, if any. Small devices that go 'floor' can stand on any open tile. */
export function slotAt(kinds, tile) {
  const s = slotsFor(kinds).find((sl) => sl.x === tile.x && tile.y >= sl.y && tile.y < sl.y + sl.h);
  if (s) return s;
  const list = Array.isArray(kinds) ? kinds : String(kinds || '').split(',');
  return list.includes('floor') && openFloor(tile.x, tile.y) ? { kind: 'floor', x: tile.x, y: tile.y, h: 1 } : null;
}

export const furnitureOf = (type) => [...LOC.furniture, ...USER_FURNITURE].filter((f) => f.type === type);

/** The bought furniture covering a tile, if any. */
export function userFurnitureAt(tile) {
  return USER_FURNITURE.find((f) => {
    const [w, h] = FOOTPRINTS[f.type] || [1, 1];
    return tile.x >= f.x && tile.x < f.x + w && tile.y >= f.y && tile.y < f.y + h;
  }) || null;
}
