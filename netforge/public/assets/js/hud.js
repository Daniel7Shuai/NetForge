// Everything outside the map canvas: top bar, tool rail, drawer, console, tags.
import { drawIcon } from './game/art.js';
import { CABLE_FITS } from './rules.js';
import { devicePixel } from './game/MapScene.js';
import { TILE, COLS, ROWS } from './game/layout.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
import { fmt, rate } from './money.js';
/** A control's explanation, whether it's still in title or already moved to data-tip by the tooltips. */
const tipOf = (el) => el.dataset.tip || el.title || '';

/** Shop and inventory categories, in Packet Tracer's order. */
const GROUPS = [
  ['end', 'End devices', 'ti-device-desktop'], ['network', 'Network devices', 'ti-router'], ['security', 'Security', 'ti-shield-lock'],
  ['gpu', 'GPUs', 'ti-cpu'], ['cable', 'Cables', 'ti-plug'], ['furniture', 'Furniture', 'ti-armchair'],
];
const groupOf = (i) => i.group || (i.category === 'cable' ? 'cable' : i.category === 'part' ? 'gpu' : i.category === 'furniture' ? 'furniture' : 'end');
const HOW = {
  device: 'Click it, then a glowing spot on the map (or drag it there).',
  cable: 'Use it with the cable tool (C).',
  furniture: 'Click it, then an open bit of floor on the map.',
  part: 'Click one of your servers to fit it.',
};
const DETAIL = {
  device: 'Click a glowing spot on the map, or drag it there. Esc to cancel.',
  cable: 'Pick the cable tool (C) to run it.',
  furniture: 'Click open floor on the map to set it down: the green outline shows where it goes. Esc to cancel.',
  part: 'Click one of your servers on the map, then pick this card under GPU card.',
};

function icon(item) {
  const c = document.createElement('canvas');
  c.width = 16; c.height = 16;
  drawIcon(c.getContext('2d'), item);
  return c;
}

export function mountHud(store) {
  // badge pop-up state; declared first because the first render can already show a badge
  const shownBadges = new Set();
  const badgeQueue = [];
  let toastBusy = false;
  // GPU offers count their restock down from when the state arrived (declared first: the first render uses them)
  let quotedAt = performance.now(), lastShop = null;

  /* tool rail, drawer tabs, cable bar */
  document.querySelectorAll('[data-tool]').forEach((b) => b.addEventListener('click', () => store.setTool(b.dataset.tool)));
  document.querySelectorAll('[data-tab]').forEach((b) => b.addEventListener('click', () => store.setTab(b.dataset.tab)));
  document.querySelectorAll('[data-cable]').forEach((b) => b.addEventListener('click', () => store.setCable(b.dataset.cable)));

  /* menu */
  const menuBtn = $('menu-btn'), menu = $('menu');
  const closeMenu = () => { menu.hidden = true; menuBtn.setAttribute('aria-expanded', 'false'); };
  menuBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    menu.hidden = !menu.hidden;
    menuBtn.setAttribute('aria-expanded', String(!menu.hidden));
  });
  document.addEventListener('click', (e) => { if (!menu.contains(e.target)) closeMenu(); });

  /* places: travel to, or lease, another location */
  const placeBtn = $('place-btn'), places = $('places');
  const closePlaces = () => { places.hidden = true; placeBtn.setAttribute('aria-expanded', 'false'); };
  placeBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    places.hidden = !places.hidden;
    placeBtn.setAttribute('aria-expanded', String(!places.hidden));
  });
  document.addEventListener('click', (e) => { if (!places.contains(e.target) && e.target !== placeBtn) closePlaces(); });
  places.addEventListener('click', (e) => {
    const b = e.target.closest('[data-go]');
    if (!b) return;
    if (b.getAttribute('aria-disabled') === 'true') { if (tipOf(b)) store.log('warn', tipOf(b)); return; }
    closePlaces();
    store.travel(b.dataset.go);
  });

  /* keyboard shortcuts (ignored while typing in a field) */
  addEventListener('keydown', (e) => {
    if ((e.target instanceof Element && e.target.closest('input, textarea, select')) || e.ctrlKey || e.metaKey || e.altKey) return;
    if (store.state.learnOpen || store.state.socialOpen || store.state.attackOpen) return; // panels handle their own keys
    const k = e.key.toLowerCase();
    const map = { l: () => document.querySelector('[data-open-learn]').click(),
      g: () => document.querySelector('[data-open-social]').click(),
      v: () => store.setTool('select'), c: () => store.setTool('cable'), x: () => store.setTool('inspect'),
      delete: () => store.setTool('remove'), i: () => store.setTab('inv'), b: () => store.setTab('shop'),
      escape: () => { closeMenu(); closePlaces(); store.cancel(); } };
    if (map[k]) { map[k](); }
  });

  /* drawer clicks (delegated, since panes re-render) */
  $('pane-inv').addEventListener('click', (e) => {
    const slot = e.target.closest('[data-item]');
    if (slot) store.selectItem(slot.dataset.item);
  });
  $('pane-shop').addEventListener('click', (e) => {
    const chip = e.target.closest('[data-shop-group]');
    if (chip) { store.set({ shopGroup: chip.dataset.shopGroup }); return; }
    const btn = e.target.closest('[data-buy]');
    if (!btn) return;
    if (btn.getAttribute('aria-disabled') === 'true') { store.log('warn', tipOf(btn)); return; }
    store.buy(Number(btn.dataset.buy));
  });

  /* port popover */
  $('picker').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.port) store.pickPort(b.dataset.port);
    else if (b.dataset.unplug) store.unplug(Number(b.dataset.unplug));
    else if (b.dataset.pickup) store.pickUp(Number(b.dataset.pickup));
    else if (b.dataset.move) store.startMove(Number(b.dataset.move));
    else if (b.dataset.card) store.installCard(Number(b.dataset.dev), b.dataset.card);
    else if (b.dataset.upgrade) {
      if (b.getAttribute('aria-disabled') === 'true') { store.log('warn', tipOf(b)); return; }
      store.upgrade(Number(b.dataset.upgrade));
    }
    else if (b.dataset.closePicker) store.set({ picker: null });
  });

  /* drag a device from the inventory onto the map */
  $('pane-inv').addEventListener('dragstart', (e) => {
    const slot = e.target.closest('[data-item]');
    if (!slot || (slot.dataset.category !== 'device' && slot.dataset.category !== 'furniture')) { e.preventDefault(); return; }
    e.dataTransfer.setData('text/plain', slot.dataset.item);
    e.dataTransfer.effectAllowed = 'move';
    store.selectItem(slot.dataset.item, true);
  });
  const mapEl = $('map');
  const tileFromEvent = (e) => {
    const r = mapEl.querySelector('canvas').getBoundingClientRect();
    return {
      x: Math.floor(((e.clientX - r.left) / r.width) * COLS),
      y: Math.floor(((e.clientY - r.top) / r.height) * ROWS),
    };
  };
  mapEl.addEventListener('dragover', (e) => {
    if (!store.placingItem()) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    store.scene?.hoverTile(tileFromEvent(e));
  });
  mapEl.addEventListener('dragleave', (e) => { if (!mapEl.contains(e.relatedTarget)) store.scene?.clearHover(); });
  mapEl.addEventListener('drop', (e) => {
    e.preventDefault();
    store.place(tileFromEvent(e));
  });

  let lastLog = null;
  let lastEventAt = 0;
  store.subscribe(render);
  render(store.state);

  function render(s) {
    if (!s.user) return;
    /* top bar */
    $('level').textContent = `Lv ${s.user.level}`;
    const into = s.user.xp % s.user.xpPerLevel;
    $('xp-fill').style.width = `${(into / s.user.xpPerLevel) * 100}%`;
    $('xp-text').textContent = `${into}/${s.user.xpPerLevel}`;
    $('who').textContent = s.user.name === s.user.username ? `Signed in as ${s.user.username}` : `Signed in as ${s.user.name} (${s.user.username})`;
    $('loc-name').textContent = s.user.locationName;
    if (s.progress?.newBadges?.length) showBadges(s.progress.newBadges);
    if (s.progress) {
      const st = s.progress.streak;
      $('streak').querySelector('span').textContent = st.current;
      $('streak').classList.toggle('lit', st.today);
      $('streak').title = `Learning streak: ${st.current} day${st.current === 1 ? '' : 's'} (best ${st.best}).${st.today ? '' : ' Answer a question today to keep it.'}`;
    }
    const learnBtn = document.querySelector('.learn-tool');
    let badge = learnBtn.querySelector('.badge');
    if (!badge) { badge = document.createElement('span'); badge.className = 'badge mono'; learnBtn.append(badge); }
    badge.textContent = s.user.auditDue;
    badge.hidden = !s.user.auditDue;
    learnBtn.setAttribute('aria-label', `Learn (L)${s.user.auditDue ? `, ${s.user.auditDue} audit questions due` : ''}`);
    const o = s.objective;
    $('obj-title').textContent = o ? o.title : 'Keep learning (L) to master topics and unlock new gear';
    const objEl = $('obj-title').closest('.objective');
    objEl.classList.toggle('done', !o);
    if (s.lastEvent && s.lastEvent.at !== lastEventAt) {
      lastEventAt = s.lastEvent.at;
      if (s.lastEvent.type === 'objective') {
        objEl.classList.remove('flash');
        void objEl.offsetWidth; // restart the animation
        objEl.classList.add('flash');
      }
    }
    document.body.classList.toggle('placing', !!store.placingItem());
    document.body.classList.toggle('busy', s.busy);
    $('obj-steps').innerHTML = o
      ? Array.from({ length: o.total }, (_, i) => `<span class="pip${i < o.index ? ' on' : ''}"></span>`).join('') + `<span>${o.index}/${o.total}</span>`
      : '';

    /* rail + tabs + cable bar */
    document.querySelectorAll('[data-tool]').forEach((b) => b.classList.toggle('on', b.dataset.tool === s.tool));
    document.querySelectorAll('[data-tab]').forEach((b) => {
      b.classList.toggle('on', b.dataset.tab === s.tab);
      if (b.getAttribute('role') === 'tab') b.setAttribute('aria-selected', String(b.dataset.tab === s.tab));
    });
    $('pane-inv').hidden = s.tab !== 'inv';
    $('pane-shop').hidden = s.tab !== 'shop';
    $('cablebar').hidden = s.tool !== 'cable';
    document.querySelectorAll('[data-cable]').forEach((b) => {
      b.classList.toggle('on', b.dataset.cable === s.cable);
      b.setAttribute('aria-checked', String(b.dataset.cable === s.cable));
      const n = store.cableCount(b.dataset.cable);
      let count = b.querySelector('.count');
      if (!count) { count = document.createElement('span'); count.className = 'count mono'; b.append(count); }
      count.textContent = n;
      b.classList.toggle('none', n === 0);
    });

    renderInventory(s);
    renderShop(s);
    renderTags(s);
    renderPicker(s);
    renderPlaces(s);
    const logSig = s.log.map((l) => l.time + l.message).join('\n');
    if (logSig !== lastLog) { renderConsole(s); lastLog = logSig; }
  }

  function renderInventory(s) {
    const pane = $('pane-inv');
    pane.innerHTML = '';
    const owned = s.inventory.filter((i) => i.quantity > 0);
    for (const [g, label] of GROUPS) {
      const items = owned.filter((i) => groupOf(i) === g);
      // devices and cables always show (with empty slots), the rest only once you own some
      if (!items.length && g !== 'end' && g !== 'cable') continue;
      const group = document.createElement('div');
      group.className = 'group';
      group.innerHTML = `<h4>${label}</h4><div class="slots"></div>`;
      const slots = group.querySelector('.slots');
      for (const item of items) {
        const b = document.createElement('button');
        b.className = 'slot' + (s.selectedItem === item.code ? ' sel' : '');
        b.dataset.item = item.code;
        b.dataset.category = item.category;
        b.draggable = item.category === 'device' || item.category === 'furniture';
        b.setAttribute('aria-label', `${item.name}, ${item.quantity}`);
        b.dataset.tip = `${item.name} ×${item.quantity}. ${item.description || ''} ${HOW[item.category] || ''}`;
        b.setAttribute('aria-pressed', String(s.selectedItem === item.code));
        b.append(icon(item));
        b.insertAdjacentHTML('beforeend', `<span class="n">${item.quantity}</span>`);
        slots.append(b);
      }
      const pad = Math.max(4 - items.length, (4 - (items.length % 4)) % 4);
      for (let i = 0; i < pad; i++) slots.insertAdjacentHTML('beforeend', '<div class="slot empty"></div>');
      pane.append(group);
    }
    const sel = s.inventory.find((i) => i.code === s.selectedItem);
    pane.insertAdjacentHTML('beforeend', sel
      ? `<div class="detail"><strong>${esc(sel.name)}</strong><div class="spec">${esc(sel.description || '')}</div><p>${esc(DETAIL[sel.category] || '')}</p></div>`
      : '<p class="empty-note">Select an item to see what it does.</p>');
  }


  /** A seller's live offer: price against the list price, stock, and when they restock. */
  function restockLeft(q) { return Math.max(0, q.restockIn - (performance.now() - quotedAt) / 1000); }

  function offerLine(row) {
    const q = row.quote, ch = q.change;
    const trend = ch > 0 ? `<span class="chg up">▲ ${ch}% over list</span>`
      : ch < 0 ? `<span class="chg down">▼ ${-ch}% under list</span>` : '<span class="chg">list price</span>';
    const mins = Math.max(1, Math.ceil(restockLeft(q) / 60));
    const stock = q.stock > 0 ? `${q.stock} in stock` : `<span class="soldout">Sold out</span>, back in ${mins} min`;
    return `${trend}<span>${stock}</span>`;
  }

  /** How a GPU compares with the standard model: 80% for the budget build, 115% for the pro. */
  function perfOf(row, shop) {
    const std = shop.find((r) => r.code === row.code.replace(/_(budget|pro)$/, '_std'));
    if (!std) return null;
    const mine = row.earns || row.boost, base = std.earns || std.boost;
    return base ? Math.round((mine / base) * 100) : null;
  }

  function shopRow(s, row) {
    const div = document.createElement('div');
    div.className = 'row' + (row.locked ? ' locked' : '') + (row.seller ? ' offer' : '');
    div.append(icon(row));
    const qty = row.quantity > 1 ? `, ${row.quantity} pack` : '';
    // what still stands between the player and this item: their XP level, then any required world
    const needLevel = row.unlockLevel > s.user.level;
    const lockText = needLevel ? `Reach level ${row.unlockLevel} to unlock` : `Master Topic ${row.unlockWorld} to unlock`;
    const lockTip = needLevel ? `Reach level ${row.unlockLevel} to buy the ${esc(row.name)}. Answer questions in Learn (L) to earn XP.` : `Master Topic ${row.unlockWorld} in Learn to unlock the ${esc(row.name)}.`;
    const have = store.liveCredits ? store.liveCredits() : s.user.credits;
    const price = row.quote ? row.quote.price : row.price;
    const perk = row.earns ? `+${rate(row.earns)}` : row.boost ? `+${row.boost}%` : '';
    const earns = row.earns ? ` Earns ${rate(row.earns)} once it’s online.` : row.boost ? ` Fit it in a server for +${row.boost}% income.` : '';
    const soldOut = row.quote && row.quote.stock < 1;
    const buyTip = soldOut
      ? `${row.seller} is sold out. They restock in ${Math.max(1, Math.ceil(restockLeft(row.quote) / 60))} minutes, or try another seller.`
      : `Buy the ${row.name}${qty}${row.seller ? ` from ${row.seller}` : ''} for ${fmt(price)} credits.${earns}${have < price ? ` You need ${fmt(price - have)} more: your network keeps earning while you play.` : ''}`;
    const perf = row.seller ? perfOf(row, s.shop) : null;
    const title = row.seller
      ? `${esc(row.seller)} <span class="what">${esc(row.name)}${perf ? `, ${perf}% performance` : ''}</span>`
      : `${esc(row.name)}${qty}`;
    const sub = row.locked ? lockText : row.seller ? offerLine(row) : esc(row.description || '');
    div.insertAdjacentHTML('beforeend', `<div><strong>${title}${perk ? ` <span class="earns mono">${perk}</span>` : ''}</strong><small class="${row.seller && !row.locked ? 'offerline' : ''}">${sub}</small></div>`
      + (row.locked
        ? `<button class="buy" aria-disabled="true" data-tip="${lockTip}"><i class="ti ti-lock" aria-hidden="true"></i>${needLevel ? `Lv ${row.unlockLevel}` : `T${row.unlockWorld}`}</button>`
        : soldOut
          ? `<button class="buy" aria-disabled="true" data-buy="${row.id}" data-tip="${esc(buyTip)}"><i class="ti ti-package-off" aria-hidden="true"></i>${fmt(price)}</button>`
          : `<button class="buy" data-buy="${row.id}" aria-label="Buy ${esc(row.name)}${row.seller ? ` from ${esc(row.seller)}` : ''} for ${fmt(price)} credits" data-tip="${esc(buyTip)}"><i class="ti ti-coins" aria-hidden="true"></i>${fmt(price)}</button>`));
    return div;
  }

  function renderShop(s) {
    if (s.shop !== lastShop) { lastShop = s.shop; quotedAt = performance.now(); }
    const pane = $('pane-shop');
    const scroll = pane.scrollTop;
    pane.innerHTML = '';
    const pick = s.shopGroup || 'all';
    const groups = GROUPS.filter(([g]) => s.shop.some((r) => (r.group || 'end') === g));
    const chips = document.createElement('div');
    chips.className = 'chips';
    chips.setAttribute('role', 'tablist');
    chips.setAttribute('aria-label', 'Shop categories');
    chips.innerHTML = [['all', 'All', 'ti-layout-grid'], ...groups].map(([g, label, ic]) =>
      `<button type="button" role="tab" data-shop-group="${g}" aria-selected="${g === pick}" class="${g === pick ? 'on' : ''}"><i class="ti ${ic}" aria-hidden="true"></i>${label}</button>`).join('');
    pane.append(chips);
    for (const [g, label] of groups) {
      if (pick !== 'all' && pick !== g) continue;
      const rows = s.shop.filter((r) => (r.group || 'end') === g);
      const sec = document.createElement('div');
      sec.className = 'shop-sec';
      sec.dataset.group = g;
      sec.innerHTML = `<h4>${label}</h4>`;
      if (g === 'gpu') {
        const deal = (s.events?.buffs || []).find((b) => b.kind === 'gpu_discount');
        sec.insertAdjacentHTML('beforeend', deal
          ? `<p class="deal"><i class="ti ti-discount" aria-hidden="true"></i>Vex’s deal: ${deal.pct}% off every GPU, ${Math.max(1, Math.ceil(deal.secondsLeft / 60))} min left</p>`
          : '<p class="note">Three sellers. Prices move and stock refills every 20 minutes, so shop around.</p>');
        // rigs first, then the cards, each with its sellers
        for (const [fam, famLabel] of [['gpu_rig', 'GPU rigs: rack devices that earn'], ['gpu_card', 'GPU cards: fit one in a server for more income']]) {
          const famRows = rows.filter((r) => r.code.startsWith(fam));
          if (!famRows.length) continue;
          sec.insertAdjacentHTML('beforeend', `<h5>${famLabel}</h5>`);
          famRows.forEach((r) => sec.append(shopRow(s, r)));
        }
      } else {
        rows.forEach((r) => sec.append(shopRow(s, r)));
      }
      pane.append(sec);
    }
    pane.scrollTop = scroll;
  }

  function renderTags(s) {
    const box = $('tags');
    box.innerHTML = '';
    for (const d of s.devices) {
      const px = devicePixel(d);
      const linked = s.cables.filter((c) => c.a_device_id === d.id || c.b_device_id === d.id);
      const cls = linked.some((c) => c.status === 'down') ? 'down' : linked.some((c) => c.status === 'up') ? 'up' : '';
      const tag = document.createElement('div');
      tag.className = 'tag ' + cls;
      tag.style.left = `${((px.x + px.w / 2) / (COLS * TILE)) * 100}%`;
      tag.style.top = `${((px.y - 4) / (ROWS * TILE)) * 100}%`;
      tag.innerHTML = `<b></b>${esc(d.label)}${d.tier > 1 ? ` <span class="t">T${d.tier}</span>` : ''}`;
      if (d.id === s.moving) tag.classList.add('act');
      box.append(tag);
    }
  }

  /* badge pop-up: one at a time, each shown once (state declared at the top of mountHud) */
  function showBadges(list) {
    for (const b of list) if (!shownBadges.has(b.code)) { shownBadges.add(b.code); badgeQueue.push(b); }
    nextToast();
  }
  function nextToast() {
    if (toastBusy || !badgeQueue.length) return;
    const b = badgeQueue.shift();
    const t = $('toast');
    toastBusy = true;
    t.innerHTML = `<i class="ti ${esc(b.icon)}" aria-hidden="true"></i><div><strong>Badge earned: ${esc(b.name)}</strong><small>${esc(b.description)}</small></div>`;
    t.hidden = false;
    t.classList.remove('out');
    setTimeout(() => {
      t.classList.add('out');
      setTimeout(() => { t.hidden = true; toastBusy = false; nextToast(); }, 300);
    }, 3800);
  }

  function renderPicker(s) {
    const el = $('picker');
    const dev = s.picker && s.devices.find((d) => d.id === s.picker.deviceId);
    if (!dev) { el.hidden = true; el.innerHTML = ''; return; }
    const W = COLS * TILE, H = ROWS * TILE, px = devicePixel(dev);
    const right = px.x + px.w / 2 < W * 0.6;
    el.style.left = `${((right ? px.x + px.w + 6 : px.x - 6) / W) * 100}%`;
    el.style.top = `${(Math.min(Math.max(px.y, 4), H * 0.45) / H) * 100}%`;
    el.style.transform = right ? 'none' : 'translateX(-100%)';

    const used = store.portsInUse(dev.id);
    const label = (id) => s.devices.find((d) => d.id === id)?.label || '?';
    const peer = (c) => (c.a_device_id === dev.id ? `${label(c.b_device_id)} ${c.b_port}` : `${label(c.a_device_id)} ${c.a_port}`);
    const role = (p) => (p.kind !== 'copper' ? p.kind : p.role === 'mdix' ? 'switch-style' : 'host-style');
    const draft = s.cableDraft;
    const mode = s.picker.mode;
    let sub, rows, foot;

    if (mode === 'cable') {
      sub = draft ? 'plug in the other end' : 'pick a port';
      rows = dev.ports.map((p) => {
        const c = used.get(p.name);
        let why = null;
        if (c) why = `→ ${peer(c)}`;
        else if (draft && draft.from.deviceId === dev.id) why = 'same device';
        else if (!(CABLE_FITS[s.cable] || []).includes(p.kind)) why = `needs ${p.kind === 'copper' ? 'a copper' : `a ${p.kind}`} cable`;
        const led = c ? c.status : why ? 'off' : 'free';
        const portTip = why ? `${p.name} can’t take this cable: ${why.replace('→', 'already plugged into')}.` : `Plug the ${s.cable} cable into ${p.name} (${role(p)} port).`;
        return `<button class="port ${led}" data-port="${esc(p.name)}" data-tip="${esc(portTip)}" ${why ? 'disabled' : ''}>
          <span class="led"></span><span class="pname">${esc(p.name)}</span><span class="to">${esc(why || role(p))}</span></button>`;
      }).join('');
      foot = `${esc(s.cable)} cable, ${store.cableCount(s.cable)} left. Esc to cancel.`;
    } else if (mode === 'remove') {
      sub = 'unplug or pick up';
      const plugged = dev.ports.filter((p) => used.has(p.name));
      rows = plugged.map((p) => {
        const c = used.get(p.name);
        return `<div class="port ${c.status}"><span class="led"></span><span class="pname">${esc(p.name)}</span>
          <span class="to">→ ${esc(peer(c))}</span><button class="mini-btn" data-unplug="${c.id}" data-tip="Unplug this cable. It goes back to your inventory, and the link stops earning.">Unplug</button></div>`;
      }).join('');
      if (!plugged.length) {
        rows = dev.fixed ? '<p class="note">Part of the building. It stays where it is.</p>'
          : `<p class="note">Nothing plugged in.</p><button class="wide" data-pickup="${dev.id}" data-tip="Put ${esc(dev.label)} back in your inventory, to place it somewhere else later."><i class="ti ti-hand-grab" aria-hidden="true"></i>Pick up ${esc(dev.label)}</button>`;
      }
      foot = 'Esc to close';
    } else {
      sub = 'ports';
      rows = dev.ports.map((p) => {
        const c = used.get(p.name);
        const what = c ? `→ ${peer(c)}, ${c.kind}, link ${c.status}` : `free, ${role(p)}`;
        return `<div class="port ${c ? c.status : 'free'}"><span class="led"></span><span class="pname">${esc(p.name)}</span><span class="to">${esc(what)}</span></div>`;
      }).join('');
      foot = 'C to cable, Del to unplug';
      if (!dev.fixed) rows = tierLine(dev) + rows + deviceActions(s, dev);
    }
    el.innerHTML = `<h3>${esc(dev.label)} <span>${sub}</span>
      <button class="x" data-close-picker="1" aria-label="Close"><i class="ti ti-x"></i></button></h3>${rows}<p class="foot">${foot}</p>`;
    el.hidden = false;
  }

  const tierMult = (t) => 1 + 0.5 * (t - 1);

  /** "Tier 2 of 3" plus what the device does for you: what it earns right now, or its firewall shield. */
  function tierLine(dev) {
    let perk, tip;
    if (dev.shield) {
      perk = `shield ${dev.shield}%`;
      tip = `While it’s online, this firewall blocks ${dev.shield}% of the credits a breach would take.`;
    } else if (dev.rate > 0) {
      perk = dev.earning > 0 ? `earning ${rate(dev.earning)}` : `${rate(dev.rate)} when online`;
      tip = dev.earning > 0
        ? `${dev.label} is online and earns ${rate(dev.earning)}. Upgrades raise that: x1.5 at tier 2, x2 at tier 3.`
        : `${dev.label} earns ${rate(dev.rate)} once it’s online: cable it to the ISP box, directly or through a router.`;
    } else {
      perk = 'no income';
      tip = 'This device doesn’t earn credits itself, but it can connect others that do.';
    }
    return `<p class="tierline ${dev.earning > 0 ? 'on' : ''}" data-tip="${esc(tip)}"><span>Tier <b>${dev.tier}</b> of ${dev.maxTier}</span><span>${esc(perk)}</span></p>`;
  }

  function deviceActions(s, dev) {
    const up = dev.upgrade;
    const have = store.liveCredits ? store.liveCredits() : s.user.credits;
    let upBtn;
    if (!up) {
      upBtn = '<button class="act" aria-disabled="true" data-tip="Already at its top tier."><i class="ti ti-check" aria-hidden="true"></i>Top tier</button>';
    } else {
      // what the upgrade buys: more income, or a bigger shield
      const gain = dev.shield
        ? `shield ${dev.shield}% → ${Math.min(75, (dev.shield / dev.tier) * up.tier)}%`
        : dev.rate > 0 ? `earns ${rate(dev.rate)} → ${rate((dev.rate / tierMult(dev.tier)) * tierMult(up.tier))}` : 'a higher tier';
      const why = s.user.level < up.level
        ? `Reach level ${up.level} to upgrade to tier ${up.tier} (${gain}). Answer questions in Learn (L) for XP.`
        : have < up.cost ? `Upgrading to tier ${up.tier} (${gain}) costs ${fmt(up.cost)} credits. You need ${fmt(up.cost - have)} more.` : '';
      upBtn = why
        ? `<button class="act" data-upgrade="${dev.id}" aria-disabled="true" data-tip="${esc(why)}"><i class="ti ti-lock" aria-hidden="true"></i>${s.user.level < up.level ? `Lv ${up.level}` : fmt(up.cost)}</button>`
        : `<button class="act up" data-upgrade="${dev.id}" aria-label="Upgrade to tier ${up.tier} for ${fmt(up.cost)} credits" data-tip="${esc(`Upgrade to tier ${up.tier} for ${fmt(up.cost)} credits: ${gain}.`)}"><i class="ti ti-arrow-big-up-lines" aria-hidden="true"></i>T${up.tier}, ${fmt(up.cost)}</button>`;
    }
    return `<div class="actions">${upBtn}<button class="act" data-move="${dev.id}" data-tip="Move it to another free spot. Its cables stay plugged in."><i class="ti ti-arrows-move" aria-hidden="true"></i>Move</button></div>${cardSlot(s, dev)}`;
  }

  /** Servers take a GPU card: which one is fitted, and the cards in the inventory that could go in. */
  function cardSlot(s, dev) {
    if (dev.item_code !== 'server') return '';
    const fitted = dev.gpuCard && (s.shop.find((r) => r.code === dev.gpuCard) || s.inventory.find((i) => i.code === dev.gpuCard));
    const cards = s.inventory.filter((i) => i.category === 'part' && i.quantity > 0 && i.code !== dev.gpuCard);
    const now = fitted ? `${esc(fitted.name)}, +${dev.gpuBoost}% income` : 'empty';
    const btns = cards.map((c) => `<button class="act up" data-card="${esc(c.code)}" data-dev="${dev.id}" data-tip="${esc(`Fit the ${c.name.toLowerCase()} in ${dev.label}: +${c.boost}% income.${fitted ? ' The card in it now goes back to your inventory.' : ''}`)}"><i class="ti ti-cpu" aria-hidden="true"></i>Fit +${c.boost}%</button>`).join('');
    const none = cards.length ? '' : `<p class="note">${fitted ? 'No other cards to swap in.' : 'Buy a GPU card in the shop (B), under GPUs, then fit it here.'}</p>`;
    return `<div class="cardslot"><p class="tierline ${fitted ? 'on' : ''}"><span>GPU card</span><span>${now}</span></p>${btns ? `<div class="actions">${btns}</div>` : ''}${none}</div>`;
  }

  function renderPlaces(s) {
    const el = $('places');
    const icons = { garage: 'ti-home', office: 'ti-building', datacentre: 'ti-server-2' };
    el.innerHTML = '<h4>Your places</h4>' + s.locations.map((l) => {
      let what, why = '';
      if (l.current) what = 'You’re here';
      else if (l.leased) what = 'Go';
      else if (s.user.level < l.unlockLevel) { what = `Lv ${l.unlockLevel}`; why = `Reach level ${l.unlockLevel} to lease the ${l.name.toLowerCase()}.`; }
      else if (s.user.credits < l.leaseCost) { what = `${fmt(l.leaseCost)} cr`; why = `Leasing the ${l.name.toLowerCase()} costs ${fmt(l.leaseCost)} credits. You need ${fmt(l.leaseCost - s.user.credits)} more.`; }
      else what = `Lease, ${fmt(l.leaseCost)}`;
      const off = l.current || !!why;
      return `<button type="button" data-go="${esc(l.code)}" class="${l.current ? 'here' : ''}" aria-disabled="${off}" ${why ? `title="${esc(why)}"` : ''}>
        <i class="ti ${icons[l.code] || 'ti-map-pin'}" aria-hidden="true"></i><strong>${esc(l.name)}</strong><span class="what">${esc(what)}</span>
        <small>${esc(l.pitch)}</small></button>`;
    }).join('');
  }

  function renderConsole(s) {
    const el = $('console');
    const cls = { ok: 'ok', warn: 'warn', error: 'err', you: 'you' };
    el.innerHTML = s.log.map((l) => `<div><span class="t">${esc(l.time)}</span><span class="${cls[l.kind] || ''}">${esc(l.message)}</span></div>`).join('');
    el.scrollTop = el.scrollHeight;
  }
}
