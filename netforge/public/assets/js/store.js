// One shared game state for the HUD and the map. Views subscribe; actions
// change state and notify. Gameplay actions (place, connect...) live here.
import { api } from './api.js';
import { slotAt, slotsFor, LOC } from './game/layout.js';

/** Player-facing hint for each slot kind. Mirrors Layout::KIND_HINTS in PHP. */
const KIND_HINTS = {
  bench: 'That goes on the workbench.',
  shelf: 'That goes on the shelf.',
  desk: 'PCs go on the spot beside a desk.',
  rack: 'Racks go in the marked slots in the server room.',
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
      store.set({
        user: data.user, inventory: data.inventory, shop: data.shop, objective: data.objective,
        devices: data.devices, cables: data.cables, log: store.mergedLog(), progress: data.progress, locations: data.locations || [],
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
      store.set({ selectedItem: next, tool: 'select', picker: null, cableDraft: null, moving: null });
    },
    /** Esc: back out one step (popover, then cable in hand, then the tool). */
    cancel() {
      const s = store.state;
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
    async pickPort(portName) {
      const s = store.state;
      if (!s.picker || s.busy) return;
      const deviceId = s.picker.deviceId;
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
      return item && item.category === 'device' && item.quantity > 0 ? item : null;
    },

    /** Device covering any tile of a slot, or null. */
    occupant(slot) {
      return store.state.devices.find((d) => d.id !== store.state.moving && d.tile_x === slot.x && d.tile_y < slot.y + slot.h && d.tile_y + d.height > slot.y) || null;
    },

    /** Why the item can't go on this tile (null if it can), plus the slot it would use. */
    placementCheck(item, tile) {
      if (!slotsFor(item.slotKinds).length) {
        return { slot: null, problem: `There’s no spot for a ${item.name} in the ${LOC.name.toLowerCase()}. You’ll need a bigger place.` };
      }
      const slot = slotAt(item.slotKinds, tile);
      if (!slot) return { slot: null, problem: KIND_HINTS[item.slotKinds[0]] || 'That spot doesn’t fit this item.' };
      const taken = store.occupant(slot);
      if (taken) return { slot, problem: `${taken.label} is already there. Pick a free spot.` };
      const p = store.state.playerTile;
      if (p && p.x === slot.x && p.y >= slot.y && p.y < slot.y + slot.h) return { slot, problem: 'Step off that spot first.' };
      return { slot, problem: null };
    },

    async place(tile) {
      const item = store.placingItem();
      if (!item || store.state.busy) return;
      const { slot, problem } = store.placementCheck(item, tile);
      if (problem) { store.log('error', problem); return; }
      store.set({ busy: true });
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

    setLearnOpen(open) { store.set({ learnOpen: open, uiFocus: open, selectedItem: open ? null : store.state.selectedItem }); },

    /** Called by the map when a tile is clicked. */
    tileClicked(tile, device) {
      if (store.placingItem()) { store.place(tile); return; }
      const { tool } = store.state;
      if (!device) {
        store.set({ picker: null });
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
