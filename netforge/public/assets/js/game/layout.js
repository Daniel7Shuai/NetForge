// Floor plans for every location (garage, office, ...). The data lives in
// assets/data/layout.json, which the PHP rules read too, so the client and
// server always agree on valid spots. Call setLocation() once at boot.

const L = await (await fetch(new URL('../../data/layout.json', import.meta.url))).json(); // relative to this file, so any page can import it

export const TILE = L.tile;
export const COLS = L.cols;
export const ROWS = L.rows;
export const FOOTPRINTS = L.footprints;
export const LOCATIONS = L.locations;

/** The location currently on screen. */
export let LOC = null;
export let LOC_CODE = null;

export function setLocation(code) {
  LOC_CODE = LOCATIONS[code] ? code : 'garage';
  LOC = LOCATIONS[LOC_CODE];
}

const key = (x, y) => `${x},${y}`;

/** Tiles the player cannot walk through, ignoring placed devices. */
export function staticBlocked() {
  const b = new Set();
  for (let x = 0; x < COLS; x++) for (let y = 0; y < LOC.wallRows; y++) b.add(key(x, y));
  if (LOC.bottomWall) for (let x = 0; x < COLS; x++) b.add(key(x, ROWS - 1));
  if (LOC.partition) {
    for (let y = LOC.wallRows; y < ROWS; y++) if (!LOC.partition.doorRows.includes(y)) b.add(key(LOC.partition.col, y));
  }
  for (const f of LOC.furniture) {
    const [w, h] = FOOTPRINTS[f.type] || [1, 1];
    for (let i = 0; i < w; i++) for (let j = 0; j < h; j++) b.add(key(f.x + i, f.y + j));
  }
  return b;
}

/** Spots in this location that accept any of the given slot kinds. Mirrors NetForge\Layout::slotsFor(). */
export function slotsFor(kinds) {
  const list = Array.isArray(kinds) ? kinds : String(kinds || '').split(',');
  return LOC.slots.filter((s) => list.includes(s.kind));
}

/** The slot covering a tile, if any. */
export function slotAt(kinds, tile) {
  return slotsFor(kinds).find((s) => s.x === tile.x && tile.y >= s.y && tile.y < s.y + s.h) || null;
}

export const furnitureOf = (type) => LOC.furniture.filter((f) => f.type === type);
