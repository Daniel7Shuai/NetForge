// Everything outside the map canvas: top bar, tool rail, drawer, console, tags.
import { drawIcon } from './game/art.js';
import { CABLE_FITS } from './rules.js';
import { devicePixel } from './game/MapScene.js';
import { TILE, COLS, ROWS } from './game/layout.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s).replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const fmt = (n) => Number(n).toLocaleString('en-US');

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

  /* keyboard shortcuts (ignored while typing in a field) */
  addEventListener('keydown', (e) => {
    if ((e.target instanceof Element && e.target.closest('input, textarea, select')) || e.ctrlKey || e.metaKey || e.altKey) return;
    if (store.state.learnOpen || store.state.socialOpen || store.state.attackOpen) return; // panels handle their own keys
    const k = e.key.toLowerCase();
    const map = { l: () => document.querySelector('[data-open-learn]').click(),
      g: () => document.querySelector('[data-open-social]').click(),
      v: () => store.setTool('select'), c: () => store.setTool('cable'), x: () => store.setTool('inspect'),
      delete: () => store.setTool('remove'), i: () => store.setTab('inv'), b: () => store.setTab('shop'),
      escape: () => { closeMenu(); store.cancel(); } };
    if (map[k]) { map[k](); }
  });

  /* drawer clicks (delegated, since panes re-render) */
  $('pane-inv').addEventListener('click', (e) => {
    const slot = e.target.closest('[data-item]');
    if (slot) store.selectItem(slot.dataset.item);
  });
  $('pane-shop').addEventListener('click', (e) => {
    const btn = e.target.closest('[data-buy]');
    if (!btn) return;
    if (btn.getAttribute('aria-disabled') === 'true') { store.log('warn', btn.title); return; }
    store.buy(Number(btn.dataset.buy));
  });

  /* port popover */
  $('picker').addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.port) store.pickPort(b.dataset.port);
    else if (b.dataset.unplug) store.unplug(Number(b.dataset.unplug));
    else if (b.dataset.pickup) store.pickUp(Number(b.dataset.pickup));
    else if (b.dataset.closePicker) store.set({ picker: null });
  });

  /* drag a device from the inventory onto the map */
  $('pane-inv').addEventListener('dragstart', (e) => {
    const slot = e.target.closest('[data-item]');
    if (!slot || slot.dataset.category !== 'device') { e.preventDefault(); return; }
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
    $('credits').textContent = fmt(s.user.credits);
    $('level').textContent = `Lv ${s.user.level}`;
    const into = s.user.xp % s.user.xpPerLevel;
    $('xp-fill').style.width = `${(into / s.user.xpPerLevel) * 100}%`;
    $('xp-text').textContent = `${into}/${s.user.xpPerLevel}`;
    $('who').textContent = `Signed in as ${s.user.username}`;
    $('loc-name').textContent = s.user.locationName;
    if (s.progress?.newBadges?.length) showBadges(s.progress.newBadges);
    if (s.progress) {
      const st = s.progress.streak, inc = s.progress.income;
      $('streak').querySelector('span').textContent = st.current;
      $('streak').classList.toggle('lit', st.today);
      $('streak').title = `Learning streak: ${st.current} day${st.current === 1 ? '' : 's'} (best ${st.best}).${st.today ? '' : ' Answer a question today to keep it.'}`;
      $('income').querySelector('span').textContent = `+${inc.rate}/h`;
      $('income').title = inc.devices.length
        ? `Renting out your network: ${inc.devices.join(', ')} online, ${inc.rate} credits an hour.`
        : 'No devices online, so nothing to rent out. Link them to the ISP box to start earning.';
    }
    const learnBtn = document.querySelector('.learn-tool');
    let badge = learnBtn.querySelector('.badge');
    if (!badge) { badge = document.createElement('span'); badge.className = 'badge mono'; learnBtn.append(badge); }
    badge.textContent = s.user.auditDue;
    badge.hidden = !s.user.auditDue;
    learnBtn.setAttribute('aria-label', `Learn (L)${s.user.auditDue ? `, ${s.user.auditDue} audit questions due` : ''}`);
    const o = s.objective;
    $('obj-title').textContent = o ? o.title : 'Keep learning (L) to master worlds and unlock new gear';
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
    const logSig = s.log.map((l) => l.time + l.message).join('\n');
    if (logSig !== lastLog) { renderConsole(s); lastLog = logSig; }
  }

  function renderInventory(s) {
    const pane = $('pane-inv');
    pane.innerHTML = '';
    for (const [cat, label] of [['device', 'Devices'], ['cable', 'Cables']]) {
      const items = s.inventory.filter((i) => i.category === cat && i.quantity > 0);
      const group = document.createElement('div');
      group.className = 'group';
      group.innerHTML = `<h4>${label}</h4><div class="slots"></div>`;
      const slots = group.querySelector('.slots');
      for (const item of items) {
        const b = document.createElement('button');
        b.className = 'slot' + (s.selectedItem === item.code ? ' sel' : '');
        b.dataset.item = item.code;
        b.dataset.category = item.category;
        b.draggable = item.category === 'device';
        b.setAttribute('aria-label', `${item.name}, ${item.quantity}`);
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
      ? `<div class="detail"><strong>${esc(sel.name)}</strong><div class="spec">${esc(sel.description || '')}</div><p>${sel.category === 'device' ? 'Click a glowing spot on the map, or drag it there. Esc to cancel.' : 'Pick the cable tool (C) to run it.'}</p></div>`
      : '<p class="empty-note">Select an item to see what it does.</p>');
  }

  function renderShop(s) {
    const pane = $('pane-shop');
    pane.innerHTML = '';
    for (const row of s.shop) {
      const div = document.createElement('div');
      div.className = 'row' + (row.locked ? ' locked' : '');
      div.append(icon(row));
      const qty = row.quantity > 1 ? `, ${row.quantity} pack` : '';
      // what still stands between the player and this item: their XP level, then any required world
      const needLevel = row.unlockLevel > s.user.level;
      const lockText = needLevel ? `Reach level ${row.unlockLevel} to unlock` : `Master World ${row.unlockWorld} to unlock`;
      const lockTip = needLevel ? `Reach level ${row.unlockLevel} to buy the ${esc(row.name)}. Answer questions in Learn (L) to earn XP.` : `Master World ${row.unlockWorld} in Learn to unlock the ${esc(row.name)}.`;
      div.insertAdjacentHTML('beforeend', `<div><strong>${esc(row.name)}${qty}</strong><small>${row.locked ? lockText : esc(row.description || '')}</small></div>`
        + (row.locked
          ? `<button class="buy" aria-disabled="true" title="${lockTip}"><i class="ti ti-lock" aria-hidden="true"></i>${needLevel ? `Lv ${row.unlockLevel}` : `W${row.unlockWorld}`}</button>`
          : `<button class="buy" data-buy="${row.id}" aria-label="Buy ${esc(row.name)} for ${row.price} credits"><i class="ti ti-coins" aria-hidden="true"></i>${fmt(row.price)}</button>`));
      pane.append(div);
    }
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
      tag.innerHTML = `<b></b>${esc(d.label)}`;
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
        return `<button class="port ${led}" data-port="${esc(p.name)}" ${why ? 'disabled' : ''}>
          <span class="led"></span><span class="pname">${esc(p.name)}</span><span class="to">${esc(why || role(p))}</span></button>`;
      }).join('');
      foot = `${esc(s.cable)} cable, ${store.cableCount(s.cable)} left. Esc to cancel.`;
    } else if (mode === 'remove') {
      sub = 'unplug or pick up';
      const plugged = dev.ports.filter((p) => used.has(p.name));
      rows = plugged.map((p) => {
        const c = used.get(p.name);
        return `<div class="port ${c.status}"><span class="led"></span><span class="pname">${esc(p.name)}</span>
          <span class="to">→ ${esc(peer(c))}</span><button class="mini-btn" data-unplug="${c.id}">Unplug</button></div>`;
      }).join('');
      if (!plugged.length) {
        rows = dev.fixed ? '<p class="note">Part of the building. It stays where it is.</p>'
          : `<p class="note">Nothing plugged in.</p><button class="wide" data-pickup="${dev.id}"><i class="ti ti-hand-grab" aria-hidden="true"></i>Pick up ${esc(dev.label)}</button>`;
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
    }
    el.innerHTML = `<h3>${esc(dev.label)} <span>${sub}</span>
      <button class="x" data-close-picker="1" aria-label="Close"><i class="ti ti-x"></i></button></h3>${rows}<p class="foot">${foot}</p>`;
    el.hidden = false;
  }

  function renderConsole(s) {
    const el = $('console');
    const cls = { ok: 'ok', warn: 'warn', error: 'err', you: 'you' };
    el.innerHTML = s.log.map((l) => `<div><span class="t">${esc(l.time)}</span><span class="${cls[l.kind] || ''}">${esc(l.message)}</span></div>`).join('');
    el.scrollTop = el.scrollHeight;
  }
}
