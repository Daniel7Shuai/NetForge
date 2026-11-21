// The to-do list on the map: the steps to set up a server rack (where there's a rack),
// and the items the student starred in the shop, with how close they are to affording each.
// It tells players what to save for, instead of handing them money.
import { fmt } from './money.js';
import { esc } from './util.js';
const KEY = 'nf.todo.open';

/** The rack set-up steps, in order. Each says how to do it and checks itself off. */
function rackSteps(s) {
  const mounted = s.devices.filter((d) => d.rackU != null);
  const has = (code) => s.inventory.some((i) => i.code === code && i.quantity > 0);
  const cabled = (a, b) => s.cables.some((c) => (c.a_device_id === a.id && c.b_device_id === b.id) || (c.a_device_id === b.id && c.b_device_id === a.id));
  const servers = mounted.filter((d) => d.size >= 2 && d.rate > 0);
  const switches = mounted.filter((d) => d.sprite === 'switch1u');
  const pdu = mounted.some((d) => d.item_code === 'pdu');
  return [
    { done: pdu || has('pdu'), text: 'Buy a PDU', how: 'Shop (B) → Misc. A rack has no power without one.' },
    { done: s.racks.some((r) => r.powered), text: 'Mount the PDU in a rack', how: 'Click a rack on the map, then pick the PDU under “Mount from your inventory”.' },
    { done: mounted.some((d) => d.item_code === 'patch_panel'), text: 'Mount a patch panel', how: 'Optional but tidy: cables from the room land on it, then go to the switch.', optional: true },
    { done: switches.length > 0, text: 'Mount a switch', how: 'A 1U switch near the top of the rack. Every server plugs into it.' },
    { done: servers.length > 0, text: 'Mount a server', how: 'Servers are 2U: pick a U with a free one above it.' },
    { done: servers.some((sv) => switches.some((sw) => cabled(sv, sw))), text: 'Cable the server to the switch', how: 'Right-click the rack → Rear view. Click the server’s Eth0, then a switch port.' },
    { done: servers.some((d) => d.earning > 0), text: 'Get the rack online', how: 'Cable the switch up to your router (or the ISP box). The server starts earning.' },
  ];
}

export function mountTodo(store) {
  const map = document.getElementById('map');
  const el = document.createElement('aside');
  el.className = 'todo';
  el.setAttribute('aria-label', 'To-do list');
  map.appendChild(el);
  let open = map.clientWidth >= 480; // on a phone the map is small: start folded
  let lastHtml = null; // the last render, so a repaint with nothing new keeps the buttons (and hover)
  try { const saved = localStorage.getItem(KEY); if (saved !== null) open = saved === '1'; } catch { /* no storage: use the default */ }

  el.addEventListener('click', (e) => {
    if (e.target.closest('[data-todo-toggle]')) {
      open = !open;
      try { localStorage.setItem(KEY, open ? '1' : '0'); } catch { /* fine */ }
      render(store.state);
      return;
    }
    const un = e.target.closest('[data-unwish]');
    if (un) { store.wish(un.dataset.unwish, false); return; }
    if (e.target.closest('[data-rack-guide]')) { store.startRackGuide?.(); return; }
    const go = e.target.closest('[data-shop-for]');
    if (go) { store.setTab('shop'); store.set({ shopGroup: go.dataset.shopFor }); }
  });

  // credits tick up between server states: refresh the bars every second
  setInterval(() => { if (open && !document.hidden && store.state.wishlist.length) render(store.state); }, 1000);
  store.subscribe(render);
  render(store.state);

  function render(s) {
    if (!s.user) return;
    const steps = s.racks.length && s.user.level >= 4 ? rackSteps(s) : [];
    const stepsLeft = steps.filter((x) => !x.done && !x.optional).length;
    const showSteps = steps.length && stepsLeft > 0;
    const wishes = s.wishlist.map((code) => s.shop.find((r) => r.code === code && !r.seller)).filter(Boolean);
    if (!showSteps && !wishes.length) { el.hidden = true; lastHtml = null; return; }
    el.hidden = false;
    const count = stepsLeft + wishes.length;
    let body = '';
    if (open) {
      if (showSteps) {
        const left = steps.filter((x) => !x.done); // finished steps drop off the list; the count shows them
        const next = left.find((x) => !x.optional);
        const guide = store.rackGuideActive ? '' : '<button class="tguide" data-rack-guide data-tip="Ada walks you through building a rack, with a free starter kit the first time.">Guide me</button>';
        body += `<h4>Set up a server rack <span>${steps.length - left.length}/${steps.length}</span></h4>${guide}<ol class="tsteps">${left.map((x) => `
          <li class="${x === next ? 'next' : ''}"><i class="ti ti-circle" aria-hidden="true"></i>
          <span>${esc(x.text)}${x.optional ? ' <small>(optional)</small>' : ''}${x === next ? `<small class="how">${esc(x.how)}</small>` : ''}</span></li>`).join('')}</ol>`;
      }
      if (wishes.length) {
        const have = store.liveCredits ? store.liveCredits() : s.user.credits;
        body += `<h4>Saving up for</h4><ul class="twishes">${wishes.map((w) => {
          const lvl = w.unlockLevel > s.user.level;
          const pct = Math.min(100, Math.floor((have / Math.max(1, w.price)) * 100));
          const state = lvl ? `Level ${w.unlockLevel} first` : pct >= 100 ? 'Ready to buy' : `${fmt(w.price - have)} to go`;
          return `<li class="${pct >= 100 && !lvl ? 'ready' : ''}"><button class="wname" data-shop-for="${esc(w.group || 'all')}" data-tip="Open the shop at ${esc(w.name)}">${esc(w.name)}</button>
            <button class="unwish" data-unwish="${esc(w.code)}" aria-label="Take ${esc(w.name)} off the list"><i class="ti ti-x" aria-hidden="true"></i></button>
            <div class="bar" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0" aria-valuemax="100"><i style="width:${pct}%"></i></div>
            <small>${fmt(Math.min(have, w.price))} / ${fmt(w.price)} · ${esc(state)}</small></li>`;
        }).join('')}</ul>`;
      }
    }
    const html = `<button class="todo-head" data-todo-toggle aria-expanded="${open}"><i class="ti ti-list-check" aria-hidden="true"></i>To-do <b>${count}</b><i class="ti ${open ? 'ti-chevron-up' : 'ti-chevron-down'} chev" aria-hidden="true"></i></button>${body ? `<div class="todo-body">${body}</div>` : ''}`;
    if (html !== lastHtml) { lastHtml = html; el.innerHTML = html; } // keep the buttons (and hover) when nothing changed
  }
}
