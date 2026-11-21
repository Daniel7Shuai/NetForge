// One shared game state for the HUD and the map. Views subscribe; actions
// change state and notify. Gameplay actions (place, connect...) live here.
import { api } from './api.js';
import { slotAt, slotsFor, LOC, setFurniture, furnitureProblem, userFurnitureAt, FOOTPRINTS } from './game/layout.js';

/** Where each slot kind is, for hints. Mirrors Layout::SPOTS in PHP. */
const SPOTS = {
  bench: 'on the workbench',
  shelf: 'on a shelf',
  desk: 'on the spot beside a desk',
  rack: 'in a rack slot (the office server room, the datacentre rows, or a server rack you buy)',
  floor: 'on any open bit of floor',
};
const hint = (kinds) => {
  const where = kinds.map((k) => SPOTS[k]).filter(Boolean);
  return where.length ? `It goes ${where.join(' or ')}. Pick one of the glowing spots.` : 'That spot doesn’t fit this item.';
};

export function createStore() {
  const listeners = new Set();
  const store = {
    scene: null, // set by MapScene so the HUD can drive hover during drag-and-drop
    state: {
      user: null, inventory: [], shop: [], objective: null, devices: [], cables: [], log: [],
      tool: 'select', tab: 'inv', cable: 'copper', selectedItem: null, uiFocus: false,
      playerTile: null, busy: false, lastEvent: null, learnOpen: false, locations: [], moving: null, // moving: id of the device being moved
      picker: null,        // {deviceId, mode: 'cable' | 'remove' | 'inspect'}: the port popover
      cableDraft: null,    // {kind, from: {deviceId, port}}: one end plugged in, other in hand
      freshCables: {},     // cable id -> time it was plugged in (shows "negotiating" briefly)
      furniture: [],       // furniture the student placed here
      events: null,        // {event, unread, buffs}: the broker, texts, temporary buffs
      shopGroup: 'all',    // the shop's category filter
      racks: [],           // racks in this place: {x, y, size, powered, bought}
      wishlist: [],        // item codes the student is saving up for
      rackView: null,      // {x, y, face: 'front' | 'rear', mount: item code | null, sel: device id | null}
      rackMenu: null,      // {rack, left, top}: the right-click Front/Rear menu
    },

    subscribe(fn) { listeners.add(fn); return () => listeners.delete(fn); },
    set(patch) { Object.assign(store.state, patch); listeners.forEach((fn) => fn(store.state)); },

    // Console lines come from two places: the server's event log and local
    // messages (like "pick a free spot") that never reach the server.
    serverLog: [],
    localLog: [],
    mergedLog() {
      return [...store.serverLog, ...store.localLog]
        .map((l, i) => ({ ...l, i }))
        .sort((a, b) => (a.time === b.time ? a.i - b.i : a.time < b.time ? -1 : 1))
        .slice(-50);
    },

    log(kind, message) {
      const time = new Date().toTimeString().slice(0, 8);
      store.localLog = [...store.localLog, { kind, message, time }].slice(-30);
      store.set({ log: store.mergedLog() });
    },

    applyServerState(data) {
      store.serverLog = data.log;
      setFurniture(data.furniture);
      store.set({
        furniture: data.furniture || [], events: data.events || store.state.events,
        user: data.user, inventory: data.inventory, shop: data.shop, objective: data.objective,
        devices: data.devices, cables: data.cables, log: store.mergedLog(), progress: data.progress, locations: data.locations || [],
        racks: data.racks || [], wishlist: data.wishlist || [], facility: data.facility || null,
      });
    },

    async load() {
      const data = await api.state();
      store.state.playerTile = { x: data.user.pos_x, y: data.user.pos_y };
      store.applyServerState(data);
    },

    setTool(tool) {
      store.set({ tool, selectedItem: tool === 'select' ? store.state.selectedItem : null, picker: null, cableDraft: null, moving: null });
      if (tool === 'cable') store.cableHint();
    },
    setTab(tab) { store.set({ tab }); },
    setCable(cable) { store.set({ cable, cableDraft: store.state.cableDraft ? { ...store.state.cableDraft, kind: cable } : null }); store.cableHint(); },
    selectItem(code, keep = false) {
      const next = !keep && store.state.selectedItem === code ? null : code;
      const item = store.state.inventory.find((i) => i.code === next);
      if (item && item.category === 'cable') { store.set({ cable: code }); store.setTool('cable'); return; }
      if (item && item.category === 'part') {
        store.log('info', item.moduleFor
          ? `The ${item.name} is a module: click a device it fits (${item.moduleFor.length} kinds, see its tip) and pick it under Modules.`
          : `To fit the ${item.name.toLowerCase()}, click one of your servers and pick it under GPU card.`);
      }
      store.set({ selectedItem: next, tool: 'select', picker: null, cableDraft: null, moving: null });
    },
    /** Esc: back out one step (popover, then cable in hand, then the tool). */
    cancel() {
      const s = store.state;
      if (s.rackMenu) return store.set({ rackMenu: null });
      if (s.rackView && !s.cableDraft) return store.closeRack();
      if (s.picker) return store.set({ picker: null });
      if (s.cableDraft) { store.log('info', 'Cable dropped.'); return store.set({ cableDraft: null }); }
      if (s.moving) { store.log('info', 'Move cancelled.'); return store.set({ moving: null }); }
      store.set({ selectedItem: null, tool: 'select' });
    },

    cableCount(kind) {
      return store.state.inventory.find((i) => i.code === kind)?.quantity || 0;
    },
    cableHint() {
      const kind = store.state.cable;
      if (!store.cableCount(kind)) store.log('warn', `You have no ${kind} cables. Buy some in the shop (B).`);
    },

    /** Port name -> cable, for every port in use on a device. */
    portsInUse(deviceId) {
      const used = new Map();
      for (const c of store.state.cables) {
        if (c.a_device_id === deviceId) used.set(c.a_port, c);
        if (c.b_device_id === deviceId) used.set(c.b_port, c);
      }
      return used;
    },

    /** Picks a port in the open popover: plugs in the first end, or completes the cable. */
    async pickPort(portName, onDevice = null) {
      const s = store.state;
      const deviceId = onDevice ?? s.picker?.deviceId;
      if (!deviceId || s.busy) return;
      const dev = s.devices.find((d) => d.id === deviceId);
      if (!s.cableDraft) {
        store.set({ picker: null, cableDraft: { kind: s.cable, from: { deviceId, port: portName } } });
        store.log('you', `Plugged into ${dev.label} ${portName}. Click the device for the other end.`);
        return;
      }
      const draft = s.cableDraft;
      store.set({ busy: true, picker: null });
      try {
        const before = new Set(s.cables.map((c) => c.id));
        const res = await api.connect(draft.kind, { device: draft.from.deviceId, port: draft.from.port }, { device: deviceId, port: portName });
        store.applyServerState(res.state);
        const added = res.state.cables.find((c) => !before.has(c.id));
        store.set({
          cableDraft: null,
          freshCables: added ? { ...store.state.freshCables, [added.id]: Date.now() } : store.state.freshCables,
          lastEvent: { type: 'linked', status: res.status, at: Date.now() },
        });
        if (!store.cableCount(draft.kind)) store.set({ tool: 'select' });
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    async unplug(cableId) {
      if (store.state.busy) return;
      store.set({ busy: true });
      try {
        store.applyServerState((await api.unplug(cableId)).state);
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    async pickUp(deviceId) {
      if (store.state.busy) return;
      store.set({ busy: true, picker: null });
      try {
        store.applyServerState((await api.pickUp(deviceId)).state);
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    /** The device the player is about to place (or move), if any. */
    placingItem() {
      const s = store.state;
      if (s.moving) {
        const d = s.devices.find((x) => x.id === s.moving);
        if (d) return { code: d.item_code, name: d.label, sprite: d.sprite, slotKinds: d.slotKinds, category: 'device', quantity: 1, moveId: d.id };
      }
      const item = s.inventory.find((i) => i.code === s.selectedItem);
      return item && (item.category === 'device' || item.category === 'furniture') && item.quantity > 0 ? item : null;
    },

    /** Device covering any tile of a slot, or null. */
    occupant(slot) {
      return store.state.devices.find((d) => d.id !== store.state.moving && d.tile_x === slot.x && d.tile_y < slot.y + slot.h && d.tile_y + d.height > slot.y) || null;
    },

    /** Why the item can't go on this tile (null if it can), plus the slot it would use. */
    placementCheck(item, tile) {
      if (item.category === 'furniture') {
        // furniture goes on open floor, its top-left on the tile
        const [w, h] = FOOTPRINTS[item.furniture] || [1, 1];
        return { slot: { x: tile.x, y: tile.y, w, h }, problem: furnitureProblem(item.furniture, tile.x, tile.y, store.state.devices, store.state.playerTile) };
      }
      if (!item.slotKinds.includes('floor') && !slotsFor(item.slotKinds).length) {
        return { slot: null, problem: `There’s no spot for a ${item.name} in the ${LOC.name.toLowerCase()}. ${hint(item.slotKinds)}` };
      }
      const slot = slotAt(item.slotKinds, tile);
      if (!slot) return { slot: null, problem: hint(item.slotKinds) };
      if (slot.kind === 'rack') return { slot, problem: null }; // racks hold several devices: the rack view picks the U
      const taken = store.occupant(slot);
      if (taken) return { slot, problem: `${taken.label} is already there. Pick a free spot.` };
      const p = store.state.playerTile;
      if (p && p.x === slot.x && p.y >= slot.y && p.y < slot.y + slot.h) return { slot, problem: 'Step off that spot first.' };
      return { slot, problem: null };
    },

    async place(tile) {
      const item = store.placingItem();
      if (!item || store.state.busy) return;
      // rack gear dropped on a rack: open the rack to pick its U
      const rack = store.rackCovering(tile);
      if (rack && item.slotKinds?.includes('rack')) { store.openRack(rack, { mount: item.code }); return; }
      const { slot, problem } = store.placementCheck(item, tile);
      if (problem) { store.log('error', problem); return; }
      // carry it over: the character walks up to the spot, then sets it down
      if (store.scene?.walkNear) {
        if (store.walking) return;
        store.walking = true;
        const arrived = await (item.category === 'furniture' ? store.scene.walkAround(slot) : store.scene.walkNear(slot));
        store.walking = false;
        const still = store.placingItem();
        if (!still || still.code !== item.code || still.moveId !== item.moveId) return; // cancelled on the way
        if (!arrived && store.scene.pathTo([{ x: slot.x, y: slot.y + (slot.h || 1) }]) === null) {
          // no path at all (boxed in): place it anyway rather than leave the student stuck
        } else if (!arrived) return; // another click took over
      }
      store.set({ busy: true });
      if (item.category === 'furniture') {
        try {
          const res = await api.placeFurniture(item.code, { x: slot.x, y: slot.y });
          store.applyServerState(res.state);
          const left = res.state.inventory.find((i) => i.code === item.code);
          store.set({ selectedItem: left && left.quantity > 0 ? item.code : null, lastEvent: { type: 'furniture', at: Date.now() } });
          store.log('ok', res.message);
        } catch (err) {
          store.log('error', err.message);
        } finally {
          store.set({ busy: false });
        }
        return;
      }
      if (item.moveId) {
        try {
          store.applyServerState((await api.move(item.moveId, { x: slot.x, y: slot.y })).state);
          store.set({ moving: null });
        } catch (err) {
          store.log('error', err.message);
        } finally {
          store.set({ busy: false });
        }
        return;
      }
      try {
        const res = await api.place(item.code, { x: slot.x, y: slot.y });
        store.applyServerState(res.state);
        const left = res.state.inventory.find((i) => i.code === item.code);
        // keep the item in hand for the next placement, unless the player cancelled meanwhile
        const stillHeld = store.state.selectedItem === item.code;
        store.set({
          selectedItem: stillHeld && left && left.quantity > 0 ? item.code : null,
          lastEvent: res.objectiveDone ? { type: 'objective', at: Date.now() } : { type: 'placed', label: res.placed, at: Date.now() },
        });
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    /** Picks a device up off its spot so the next map click puts it somewhere else. */
    startMove(deviceId) {
      const d = store.state.devices.find((x) => x.id === deviceId);
      if (!d || d.fixed) return;
      store.set({ picker: null, cableDraft: null, selectedItem: null, tool: 'select', moving: deviceId });
      store.log('you', `Moving ${d.label}. Click a free spot. Its cables come along. Esc to cancel.`);
    },

    async pickUpFurniture(id) {
      if (store.state.busy) return;
      store.set({ busy: true, picker: null });
      try {
        store.applyServerState((await api.pickUpFurniture(id)).state);
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    async installCard(deviceId, card) {
      if (store.state.busy) return;
      store.set({ busy: true });
      try {
        const res = await api.installCard(deviceId, card);
        store.applyServerState(res.state);
        store.set({ lastEvent: { type: 'card', at: Date.now() } });
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    async upgrade(deviceId) {
      if (store.state.busy) return;
      store.set({ busy: true });
      try {
        const res = await api.upgrade(deviceId);
        store.applyServerState(res.state);
        store.set({ lastEvent: { type: 'upgraded', at: Date.now() } });
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    /** Goes to (or leases) another location. The room changes, so the page reloads. */
    async travel(code) {
      if (store.state.busy) return;
      store.set({ busy: true });
      try {
        await api.travel(code);
        location.reload();
      } catch (err) {
        store.log('error', err.message);
        store.set({ busy: false });
      }
    },

    async buy(shopId) {
      if (store.state.busy) return;
      store.set({ busy: true });
      try {
        const res = await api.buy(shopId);
        store.applyServerState(res.state);
        store.set({ lastEvent: { type: 'bought', at: Date.now() } });
      } catch (err) {
        store.log('error', err.message);
      } finally {
        store.set({ busy: false });
      }
    },

    /* ---- racks ---- */
    rackAt(x, y) { return store.state.racks.find((r) => r.x === x && r.y === y) || null; }, // by its top tile
    /** The rack standing on a tile, if any: racks are two tiles tall. */
    rackCovering(tile) { return store.state.racks.find((r) => r.x === tile.x && (tile.y === r.y || tile.y === r.y + 1)) || null; },
    /** Devices mounted in a rack, bottom U first. */
    rackContents(rack) {
      return store.state.devices.filter((d) => d.rackU != null && d.tile_x === rack.x && d.tile_y === rack.y).sort((a, b) => a.rackU - b.rackU);
    },
    /** U numbers free in a rack (ignoring one device, when sliding it). */
    rackFree(rack, ignoreId = null) {
      const used = new Set();
      for (const d of store.rackContents(rack)) if (d.id !== ignoreId) for (let u = d.rackU; u < d.rackU + d.size; u++) used.add(u);
      return Array.from({ length: rack.size }, (_, i) => i + 1).filter((u) => !used.has(u));
    },
    /** Would something size U tall fit with its bottom at U u? */
    rackFits(rack, u, size, ignoreId = null) {
      const free = new Set(store.rackFree(rack, ignoreId));
      for (let k = u; k < u + size; k++) if (!free.has(k)) return false;
      return true;
    },
    openRack(rack, opts = {}) {
      store.set({
        rackMenu: null, picker: null,
        rackView: { x: rack.x, y: rack.y, face: opts.face || 'front', mount: opts.mount || null, sel: opts.sel ?? null },
        uiFocus: true,
      });
      store.scene?.clearHover?.();
    },
    closeRack() { store.set({ rackView: null, rackMenu: null, uiFocus: false }); },
    rackMenu(rack, ev) {
      const r = document.getElementById('map').getBoundingClientRect();
      store.set({ rackMenu: { rack, left: ev.clientX - r.left, top: ev.clientY - r.top }, picker: null });
    },
    /** Runs a server call with the busy flag up; errors go to the console. Returns whether it worked. */
    async busyCall(fn) {
      if (store.state.busy) return false;
      store.set({ busy: true });
      try {
        await fn();
        return true;
      } catch (err) {
        store.log('error', err.message);
        return false;
      } finally {
        store.set({ busy: false });
      }
    },
    rackCall(action, body, after) {
      return store.busyCall(async () => {
        const res = await api.rack(action, body);
        store.applyServerState(res.state);
        after?.(res);
      });
    },
    /** Mounts the item in hand (or the device being moved) in the open rack at U u. */
    async mountAt(u) {
      const v = store.state.rackView;
      if (!v?.mount) return;
      const item = store.placingItem();
      if (item?.moveId) {
        // from elsewhere into this rack: move it in, then slide it to the U picked
        await store.busyCall(async () => {
          store.applyServerState((await api.move(item.moveId, { x: v.x, y: v.y })).state);
          const d = store.state.devices.find((x) => x.id === item.moveId);
          if (d && d.rackU !== u) store.applyServerState((await api.rack('shift', { device: d.id, u })).state);
          store.set({ moving: null, rackView: { ...v, mount: null, sel: item.moveId } });
        });
        return;
      }
      const code = v.mount;
      await store.rackCall('mount', { item: code, x: v.x, y: v.y, u }, () => {
        const left = store.state.inventory.find((i) => i.code === code)?.quantity || 0;
        const mounted = store.state.devices.find((d) => d.tile_x === v.x && d.tile_y === v.y && d.rackU === u);
        store.set({
          selectedItem: left > 0 && store.state.selectedItem === code ? code : null,
          rackView: { ...store.state.rackView, mount: null, sel: mounted?.id ?? null },
          lastEvent: { type: 'mounted', item: code, at: Date.now() },
        });
      });
    },
    /** Picks rack gear from the inventory to mount in the open rack. */
    chooseMount(code) {
      const v = store.state.rackView;
      if (!v) return;
      store.set({ selectedItem: code, moving: null, rackView: { ...v, mount: code, face: 'front' } });
    },
    shift: (deviceId, u) => store.rackCall('shift', { device: deviceId, u }),
    installModule: (deviceId, code) => store.rackCall('module', { device: deviceId, item: code }, () => store.set({ lastEvent: { type: 'module', at: Date.now() } })),
    removeModule: (deviceId, bay) => store.rackCall('unmodule', { device: deviceId, bay }),
    /** Stars (or unstars) a shop item: shows at once, and the server keeps the list. */
    async wish(code, on) {
      const before = store.state.wishlist;
      store.set({ wishlist: on ? [...before.filter((c) => c !== code), code] : before.filter((c) => c !== code) });
      try {
        store.set({ wishlist: (await api.rack('wish', { item: code, on })).wishlist });
      } catch (err) {
        store.set({ wishlist: before });
        store.log('error', err.message);
      }
    },

    setLearnOpen(open) { store.set({ learnOpen: open, uiFocus: open, selectedItem: open ? null : store.state.selectedItem }); },

    /** Called by the map when a tile is clicked. */
    tileClicked(tile, device) {
      if (store.placingItem()) { store.place(tile); return; }
      const { tool } = store.state;
      if (!device) {
        store.set({ picker: null });
        const f = tool === 'remove' && userFurnitureAt(tile);
        if (f) store.pickUpFurniture(f.id);
        return;
      }
      if (tool === 'cable') {
        if (!store.state.cableDraft && !store.cableCount(store.state.cable)) { store.cableHint(); return; }
        store.set({ picker: { deviceId: device.id, mode: 'cable' } });
        return;
      }
      store.set({ picker: { deviceId: device.id, mode: tool === 'remove' ? 'remove' : 'inspect' } });
    },

    playerMoved(tile) {
      store.state.playerTile = tile; // no re-render needed
      // TODO(gameplay): save position, proximity checks for interactions.
    },
  };
  return store;
}
