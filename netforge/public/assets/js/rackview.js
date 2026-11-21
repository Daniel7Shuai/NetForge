// The rack, seen as if you were standing in front of it (or behind it).
// Front: what's mounted and its status lights. Rear: the ports, where cables go.
// Right-clicking a rack on the map offers either face; clicking opens the front.
import { drawIcon } from './game/art.js';
import { CABLE_FITS } from './rules.js';
import { fmt, rate } from './money.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

/** Which face a sprite gets in the rack view. */
const FACE = {
  rack2u: 'server', nas2u: 'storage', san3u: 'storage', gpurig: 'gpu',
  switch1u: 'switch', patch1u: 'patch', kvm1u: 'kvm', lb1u: 'switch', ctl1u: 'switch', wlc1u: 'switch',
  router1u: 'router', asa1u: 'router', pdu1u: 'pdu', ups3u: 'ups', blank1u: 'blank', cablemgmt1u: 'cablemgmt',
};
const faceOf = (d) => FACE[d.sprite] || (d.size >= 2 ? 'server' : 'switch');

function iconCanvas(item) {
  const c = document.createElement('canvas');
  c.width = 16; c.height = 16;
  drawIcon(c.getContext('2d'), item);
  return c;
}

/** Modules a device has, and the ones in the inventory that fit it. Used here and by the map's device panel. */
export function moduleSlot(s, dev) {
  if (!dev.bays) return '';
  const name = (code) => (s.shop.find((r) => r.code === code) || s.inventory.find((i) => i.code === code))?.name || code;
  const fitted = dev.modules.map((m, bay) => `<div class="port up"><span class="led"></span><span class="pname">Bay ${bay}</span><span class="to">${esc(name(m))}</span>
    <button class="mini-btn" data-unmodule="${bay}" data-dev="${dev.id}" data-tip="Take the module out. Unplug its cable first, if it has one.">Remove</button></div>`).join('');
  const fits = s.inventory.filter((i) => i.moduleFor && i.quantity > 0 && i.moduleFor.includes(dev.item_code));
  const free = dev.bays - dev.modules.length;
  const btns = free > 0 ? fits.map((m) => `<button class="act up" data-module="${esc(m.code)}" data-dev="${dev.id}" data-tip="${esc(`Fit the ${m.name} in a free bay. ${m.description || ''}`)}"><i class="ti ti-plug-connected" aria-hidden="true"></i>${esc(m.name)}</button>`).join('') : '';
  const note = free <= 0 ? '' : fits.length ? '' : '<p class="note">No modules for it in your inventory. The shop sells them under Modules.</p>';
  return `<div class="cardslot"><p class="tierline ${dev.modules.length ? 'on' : ''}"><span>Modules</span><span>${dev.modules.length} of ${dev.bays} bays</span></p>${fitted}${btns ? `<div class="actions">${btns}</div>` : ''}${note}</div>`;
}

export function mountRackView(store) {
  const map = $('map');
  const el = document.createElement('div');
  el.className = 'rackview';
  el.id = 'rackview';
  el.hidden = true;
  el.setAttribute('role', 'dialog');
  el.setAttribute('aria-label', 'Server rack');
  map.appendChild(el);

  const menu = document.createElement('div');
  menu.className = 'rackmenu';
  menu.hidden = true;
  menu.setAttribute('role', 'menu');
  map.appendChild(menu);

  let hoverU = null; // where the item in hand would go, while choosing a U
  let lastHtml = ''; // the last render, so unchanged state doesn't rebuild the DOM

  const view = () => store.state.rackView;
  const rack = () => { const v = view(); return v && store.rackAt(v.x, v.y); };
  const setView = (patch) => store.set({ rackView: { ...view(), ...patch } });

  /* clicks */
  el.addEventListener('click', (e) => {
    const b = e.target.closest('[data-act], [data-u], [data-dev-sel], [data-rport], [data-mount-item], [data-module], [data-unmodule], [data-unplug], [data-cable-kind], [data-upgrade]');
    if (!b || b.disabled) return;
    if (b.getAttribute('aria-disabled') === 'true') { if (b.dataset.tip) store.log('warn', b.dataset.tip); return; }
    const s = store.state, v = view(), r = rack();
    if (!v || !r) return;
    if (b.dataset.act === 'close') { store.closeRack(); return; }
    if (b.dataset.act === 'front' || b.dataset.act === 'rear') { setView({ face: b.dataset.act }); return; }
    if (b.dataset.act === 'cancel-mount') { store.set({ selectedItem: null, moving: null }); setView({ mount: null }); return; }
    if (b.dataset.act === 'to-map') { store.closeRack(); store.log('you', 'Click the device on the map for the other end. Esc drops the cable.'); return; }
    if (b.dataset.act === 'up' || b.dataset.act === 'down') {
      const d = s.devices.find((x) => x.id === v.sel);
      if (d) store.shift(d.id, d.rackU + (b.dataset.act === 'up' ? 1 : -1));
      return;
    }
    if (b.dataset.act === 'takeout') { store.pickUp(v.sel).then(() => { if (!s.devices.some((d) => d.id === v.sel)) setView({ sel: null }); }); return; }
    if (b.dataset.act === 'move') { store.startMove(v.sel); store.closeRack(); return; }
    if (b.dataset.u) { store.mountAt(Number(b.dataset.u)); return; }
    if (b.dataset.devSel) { setView({ sel: Number(b.dataset.devSel) }); return; }
    if (b.dataset.mountItem) { store.chooseMount(b.dataset.mountItem); return; }
    if (b.dataset.module) { store.installModule(Number(b.dataset.dev), b.dataset.module); return; }
    if (b.dataset.unmodule) { store.removeModule(Number(b.dataset.dev), Number(b.dataset.unmodule)); return; }
    if (b.dataset.unplug) { store.unplug(Number(b.dataset.unplug)); return; }
    if (b.dataset.upgrade) { store.upgrade(Number(b.dataset.upgrade)); return; }
    if (b.dataset.cableKind) { store.setCable(b.dataset.cableKind); return; }
    if (b.dataset.rport) {
      const devId = Number(b.dataset.rdev);
      setView({ sel: devId });
      const c = store.portsInUse(devId).get(b.dataset.rport);
      if (c) return; // plugged in: the side panel shows it, with Unplug
      if (!s.cableDraft && !store.cableCount(s.cable)) { store.cableHint(); return; }
      store.set({ tool: 'cable' });
      store.pickPort(b.dataset.rport, devId);
    }
  });
  // hovering a U shows where the item in hand would sit (classes only: re-rendering here would eat the click)
  el.addEventListener('mouseover', (e) => {
    const u = e.target.closest('[data-u]')?.dataset.u;
    const next = u ? Number(u) : null;
    if (next !== hoverU) { hoverU = next; ghost(); }
  });
  function ghost() {
    const v = view(), r = rack();
    if (!v?.mount || !r) return;
    const size = Number(el.querySelector('.rv-rack')?.dataset.size || 1);
    const ok = hoverU != null && store.rackFits(r, hoverU, size);
    el.querySelectorAll('button[data-u]').forEach((b) => {
      const u = Number(b.dataset.u);
      b.classList.toggle('ghost', ok && u >= hoverU && u < hoverU + size);
    });
  }
  el.addEventListener('contextmenu', (e) => e.preventDefault());

  menu.addEventListener('click', (e) => {
    const b = e.target.closest('[data-face]');
    const m = store.state.rackMenu;
    if (!b || !m) return;
    const item = store.placingItem();
    if (b.dataset.face === 'mount') store.openRack(m.rack, { mount: item.code });
    else store.openRack(m.rack, { face: b.dataset.face });
  });
  document.addEventListener('pointerdown', (e) => {
    if (store.state.rackMenu && !menu.contains(e.target)) store.set({ rackMenu: null });
  }, true);

  store.subscribe(render);

  function render(s) {
    renderMenu(s);
    const v = s.rackView, r = v && store.rackAt(v.x, v.y);
    if (!r) { el.hidden = true; el.innerHTML = ''; lastHtml = ''; return; }
    const html = `<div class="rv-card">${header(s, v, r)}<div class="rv-body">${cabinet(s, v, r)}<div class="rv-side">${side(s, v, r)}</div></div></div>`;
    if (html === lastHtml) return; // nothing changed: keep the DOM (and any click in progress)
    lastHtml = html;
    const keepScroll = el.querySelector('.rv-side')?.scrollTop || 0;
    el.innerHTML = html;
    el.hidden = false;
    el.querySelector('.rv-side').scrollTop = keepScroll;
    ghost();
    el.querySelectorAll('[data-icon]').forEach((slot) => {
      const it = s.inventory.find((i) => i.code === slot.dataset.icon) || s.shop.find((i) => i.code === slot.dataset.icon);
      if (it) slot.replaceWith(iconCanvas(it));
    });
  }

  function renderMenu(s) {
    const m = s.rackMenu;
    if (!m) { menu.hidden = true; return; }
    const item = store.placingItem();
    const canMount = item && item.slotKinds?.includes('rack');
    menu.style.left = `${m.left}px`;
    menu.style.top = `${m.top}px`;
    menu.innerHTML = `<button role="menuitem" data-face="front"><i class="ti ti-layout-rows" aria-hidden="true"></i>Front view<small>what’s mounted, status lights</small></button>
      <button role="menuitem" data-face="rear"><i class="ti ti-plug" aria-hidden="true"></i>Rear view<small>ports and cables</small></button>
      ${canMount ? `<button role="menuitem" data-face="mount"><i class="ti ti-download" aria-hidden="true"></i>Mount ${esc(item.name)}</button>` : ''}`;
    menu.hidden = false;
  }

  function header(s, v, r) {
    const used = store.rackContents(r).reduce((n, d) => n + d.size, 0);
    const power = r.powered
      ? '<span class="rv-pill on" data-tip="A PDU is mounted: everything in this rack has power."><i class="ti ti-bolt" aria-hidden="true"></i>Powered</span>'
      : '<span class="rv-pill off" data-tip="No PDU in this rack, so nothing in it runs. Mount a PDU (Misc in the shop)."><i class="ti ti-bolt-off" aria-hidden="true"></i>No power</span>';
    return `<header class="rv-head"><h3>Server rack <span>${r.size}U · ${used}U used</span></h3>${power}
      <div class="rv-faces" role="tablist">
        <button role="tab" aria-selected="${v.face === 'front'}" data-act="front">Front</button>
        <button role="tab" aria-selected="${v.face === 'rear'}" data-act="rear">Rear</button>
      </div>
      <button class="x" data-act="close" aria-label="Close the rack"><i class="ti ti-x"></i></button></header>`;
  }

  /** The cabinet: 12 U from the bottom up, each device across the U it fills. */
  function cabinet(s, v, r) {
    const contents = store.rackContents(r);
    const item = v.mount && (s.inventory.find((i) => i.code === v.mount) || (store.placingItem()?.code === v.mount ? store.placingItem() : null));
    const moving = store.placingItem()?.moveId;
    const size = item ? (item.rackU || s.devices.find((d) => d.id === moving)?.size || 1) : 1;
    const rows = [];
    // rails with U numbers
    for (let u = 1; u <= r.size; u++) rows.push(`<span class="rv-u" style="grid-row:${r.size - u + 1}">${u}</span>`);
    // empty slots: clickable when choosing where the item in hand goes
    for (const u of store.rackFree(r)) {
      const row = r.size - u + 1;
      if (item) {
        const ok = store.rackFits(r, u, size);
        rows.push(`<button class="rv-empty ${ok ? 'fits' : ''}" style="grid-row:${row}" data-u="${u}" ${ok ? '' : 'aria-disabled="true"'}
          data-tip="${esc(ok ? `Mount the ${item.name} at U${u}${size > 1 ? ` to U${u + size - 1}` : ''}.` : `The ${item.name} is ${size}U: it needs U${u} to U${u + size - 1} free.`)}"></button>`);
      } else {
        rows.push(`<span class="rv-empty" style="grid-row:${row}"></span>`);
      }
    }
    for (const d of contents) {
      const top = r.size - (d.rackU + d.size - 1) + 1;
      rows.push(`<div class="rv-dev f-${faceOf(d)} ${v.sel === d.id ? 'sel' : ''} ${r.powered ? 'pw' : ''}" style="grid-row:${top} / span ${d.size}">
        ${v.face === 'rear' ? rear(s, d, r) : front(s, d, r)}</div>`);
    }
    return `<div class="rv-cab ${v.face}"><div class="rv-rack" data-size="${size}" style="grid-template-rows:repeat(${r.size}, var(--u))">${rows.join('')}</div></div>`;
  }

  function front(s, d, r) {
    const on = !r.powered ? 'off' : d.earning > 0 || !d.rate ? 'on' : 'idle'; // amber: an earner that isn't online yet
    const used = store.portsInUse(d.id);
    const k = faceOf(d);
    let body = '';
    if (k === 'server' || k === 'storage' || k === 'gpu') {
      const bays = k === 'storage' ? d.size * 6 : k === 'gpu' ? 0 : 8;
      body = k === 'gpu' ? '<span class="rv-grille"></span><span class="rv-grille"></span>'
        : `<span class="rv-drives">${Array.from({ length: bays }, (_, i) => `<i class="${r.powered ? (i % 3 ? 'g' : 'b') : ''}"></i>`).join('')}</span>`;
    } else if (k === 'switch' || k === 'router') {
      body = `<span class="rv-ports">${d.ports.map((p) => `<i class="${used.get(p.name)?.status || ''}"></i>`).join('')}</span>`;
    } else if (k === 'patch') {
      body = `<span class="rv-ports wide">${d.ports.map((p) => `<i class="${used.has(p.name) ? 'up' : ''}"></i>`).join('')}</span>`;
    } else if (k === 'pdu') {
      body = `<span class="rv-outlets">${'<i></i>'.repeat(8)}</span>`;
    } else if (k === 'ups') {
      body = `<span class="rv-lcd">${r.powered ? 'ONLINE · 100%' : 'OFF'}</span><span class="rv-grille"></span>`;
    } else if (k === 'kvm') {
      body = `<span class="rv-ports">${'<i></i>'.repeat(8)}</span>`;
    } else if (k === 'cablemgmt') {
      body = `<span class="rv-fingers">${'<i></i>'.repeat(10)}</span>`;
    }
    const plate = k === 'blank' ? '' : `<button class="rv-plate" data-dev-sel="${d.id}" data-tip="${esc(`${d.label}: ${d.name || d.item_code}. Click for details.`)}"><b>${esc(d.label)}</b><span class="rv-led ${on}"></span></button>`;
    return `${plate}${body}${k === 'blank' ? `<button class="rv-plate ghosty" data-dev-sel="${d.id}" aria-label="${esc(d.label)}"></button>` : ''}`;
  }

  function rear(s, d, r) {
    const used = store.portsInUse(d.id);
    const draft = s.cableDraft;
    const k = faceOf(d);
    const ports = d.ports.map((p) => {
      const c = used.get(p.name);
      const fits = (CABLE_FITS[s.cable] || []).includes(p.kind);
      const isFrom = draft && draft.from.deviceId === d.id && draft.from.port === p.name;
      let tip;
      if (c) tip = `${p.name}: plugged in, link ${c.status}. Click to see where it goes.`;
      else if (!fits) tip = `${p.name} is a ${p.kind} port: pick a ${p.kind === 'copper' ? 'copper or crossover' : p.kind} cable below.`;
      else tip = draft ? `Plug the other end of the ${draft.kind} cable into ${p.name}.` : `Plug a ${s.cable} cable into ${p.name}.`;
      return `<button class="rv-port k-${p.kind} ${c ? c.status : ''} ${isFrom ? 'from' : ''} ${!c && !fits ? 'nofit' : ''}" data-rport="${esc(p.name)}" data-rdev="${d.id}" data-tip="${esc(tip)}"><i></i><small>${esc(p.name)}</small></button>`;
    }).join('');
    const psu = k === 'server' || k === 'storage' || k === 'gpu' ? `<span class="rv-psu ${r.powered ? 'on' : ''}"></span><span class="rv-psu ${r.powered ? 'on' : ''}"></span>` : '';
    const fan = k === 'server' || k === 'storage' || k === 'gpu' || k === 'ups' ? `<span class="rv-fan ${r.powered ? 'spin' : ''}"></span>` : '';
    const cord = k === 'pdu' ? '<span class="rv-cord">MAINS IN</span>' : '';
    return `<button class="rv-plate" data-dev-sel="${d.id}" aria-label="${esc(d.label)} details"><b>${esc(d.label)}</b></button>${psu}${fan}${cord}<span class="rv-rports">${ports}</span>`;
  }

  function side(s, v, r) {
    const parts = [];
    const draft = s.cableDraft;
    // cable in hand
    if (v.face === 'rear' || draft) {
      const from = draft && s.devices.find((d) => d.id === draft.from.deviceId);
      const chips = ['copper', 'crossover', 'fiber', 'console'].map((k) => `<button class="rv-chip ${s.cable === k ? 'on' : ''}" data-cable-kind="${k}"><span class="sw sw-${k}"></span>${k} <small>${store.cableCount(k)}</small></button>`).join('');
      parts.push(`<section class="rv-sec"><h4>Cable</h4><div class="rv-chips">${chips}</div>
        ${draft ? `<p class="rv-note on">In hand: ${esc(draft.kind)} from ${esc(from?.label || '?')} ${esc(draft.from.port)}. Click a port here for the other end, or <button class="linkbtn" data-act="to-map">find it on the map</button>.</p>`
        : '<p class="rv-note">Click a port to plug a cable in. Servers go to a switch, the switch to your router or the ISP.</p>'}</section>`);
    }
    // choosing where to mount
    if (v.mount) {
      const it = s.inventory.find((i) => i.code === v.mount) || store.placingItem();
      parts.push(`<section class="rv-sec hl"><h4>Mounting ${esc(it?.name || v.mount)}</h4><p class="rv-note">Pick a glowing U on the left. It fills ${it?.rackU || 1}U, from that U up.</p>
        <button class="rv-btn" data-act="cancel-mount">Cancel</button></section>`);
    }
    // the selected device
    const d = v.sel && s.devices.find((x) => x.id === v.sel && x.rackU != null);
    if (d) parts.push(deviceSec(s, d, r));
    // gear in the inventory that goes in a rack
    if (!v.mount) {
      const gear = s.inventory.filter((i) => i.category === 'device' && i.quantity > 0 && i.slotKinds?.includes('rack'));
      const list = gear.map((i) => `<button class="rv-item" data-mount-item="${esc(i.code)}" data-tip="${esc(`Mount the ${i.name} in this rack (${i.rackU || 1}U).`)}"><span data-icon="${esc(i.code)}"></span>${esc(i.name)}<small>${i.rackU || 1}U · ×${i.quantity}</small></button>`).join('');
      parts.push(`<section class="rv-sec"><h4>Mount from your inventory</h4>${list || '<p class="rv-note">Nothing for a rack in your inventory. Rack gear is in the shop: Misc has PDUs, patch panels and more.</p>'}</section>`);
    }
    if (!r.powered) parts.unshift('<section class="rv-sec warn"><h4><i class="ti ti-bolt-off" aria-hidden="true"></i> No power</h4><p class="rv-note">Nothing in this rack runs until a PDU is mounted. Buy one under Misc in the shop, then mount it here.</p></section>');
    return parts.join('');
  }

  function deviceSec(s, d, r) {
    const used = store.portsInUse(d.id);
    const label = (id) => s.devices.find((x) => x.id === id)?.label || '?';
    const peer = (c) => (c.a_device_id === d.id ? `${label(c.b_device_id)} ${c.b_port}` : `${label(c.a_device_id)} ${c.a_port}`);
    const canUp = d.rackU + d.size <= r.size && store.rackFits(r, d.rackU + 1, d.size, d.id);
    const canDown = d.rackU > 1 && store.rackFits(r, d.rackU - 1, d.size, d.id);
    let what;
    if (!r.powered) what = 'off: the rack has no power';
    else if (d.shield) what = `shield ${d.shield}%`;
    else if (d.rackBoost) what = `lifts this rack +${d.rackBoost}%`;
    else if (d.netBoost > 0) what = `boosts +${d.netBoost}%`;
    else if (d.rate > 0) what = d.earning > 0 ? `earning ${rate(d.earning)}` : `${rate(d.rate)} once online`;
    else what = 'no income';
    const ports = d.ports.map((p) => {
      const c = used.get(p.name);
      return `<div class="port ${c ? c.status : 'free'}"><span class="led"></span><span class="pname">${esc(p.name)}</span><span class="to">${esc(c ? `→ ${peer(c)}` : `free, ${p.kind}`)}</span>${c ? `<button class="mini-btn" data-unplug="${c.id}">Unplug</button>` : ''}</div>`;
    }).join('');
    const up = d.upgrade;
    const upBtn = !up ? '' : s.user.level < up.level
      ? `<button class="rv-btn" aria-disabled="true" data-upgrade="${d.id}" data-tip="Reach level ${up.level} to upgrade to tier ${up.tier}.">Lv ${up.level}</button>`
      : `<button class="rv-btn up" data-upgrade="${d.id}" data-tip="Upgrade to tier ${up.tier} for ${fmt(up.cost)} credits.">T${up.tier}, ${fmt(up.cost)}</button>`;
    return `<section class="rv-sec dev"><h4>${esc(d.label)} <span>U${d.rackU}${d.size > 1 ? `–U${d.rackU + d.size - 1}` : ''}</span></h4>
      <p class="rv-what ${d.earning > 0 ? 'on' : ''}">Tier ${d.tier} of ${d.maxTier} · ${esc(what)}</p>
      <div class="rv-row">
        <button class="rv-btn" data-act="up" ${canUp ? '' : 'aria-disabled="true"'} data-tip="${canUp ? 'Slide it up one U.' : 'No room above it.'}" aria-label="Slide up"><i class="ti ti-arrow-up"></i></button>
        <button class="rv-btn" data-act="down" ${canDown ? '' : 'aria-disabled="true"'} data-tip="${canDown ? 'Slide it down one U.' : 'No room below it.'}" aria-label="Slide down"><i class="ti ti-arrow-down"></i></button>
        ${upBtn}
        <button class="rv-btn" data-act="move" data-tip="Move it to another rack or spot. Its cables come along.">Move</button>
        <button class="rv-btn" data-act="takeout" ${used.size ? 'aria-disabled="true" data-tip="Unplug its cables first."' : 'data-tip="Take it out and put it back in your inventory."'}>Take out</button>
      </div>
      <div class="picker inline">${ports}${moduleSlot(s, d)}</div></section>`;
  }
}
