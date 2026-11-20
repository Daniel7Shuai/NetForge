// Random events once the student has moved out of the garage: Vex the grey-market broker
// turns up and follows them around (talk to him for a GPU discount, or get pickpocketed),
// and investors text offers to invest (take it, turn it down or haggle: they may walk away).
// The phone in the top bar holds the texts and the company's value over time. The server
// decides everything.
import { api } from './api.js';
import { fmt } from './money.js';
import { loadStory, character } from './story.js';
import { portraitEl } from './dialogue.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const POLL_MS = 10000;

export function mountEvents(store) {
  let broker = null;      // id of the broker event on screen
  const greeted = new Set(); // broker visits he has already spoken up in
  let unread = null;
  let talking = false;
  let offerId = null;     // the investor offer on the go
  let call = null, callBusy = false; // the pop-up phone
  let since = null;       // when the current conversation with the investor started
  const btn = $('phone-btn'), panel = $('phone');

  function ring() {
    btn.classList.remove('ring'); void btn.offsetWidth; btn.classList.add('ring');
  }

  /** A notification sliding in at the top of the map, like a phone's. */
  function notify(title, body) {
    const n = document.createElement('div');
    n.className = 'notif';
    n.setAttribute('role', 'status');
    n.innerHTML = `<i class="ti ti-message-circle" aria-hidden="true"></i><div><b>${esc(title)}</b><small>${esc(body)}</small></div>`;
    document.querySelector('.stage').append(n);
    setTimeout(() => n.classList.add('out'), 4200);
    setTimeout(() => n.remove(), 4600);
  }

  /* ---------- the investor: a phone that pops up like a character ---------- */
  const blocked = () => store.state.learnOpen || store.state.socialOpen || store.state.attackOpen || document.querySelector('.dlg');

  async function openCall() {
    if (offerId === null) return;
    if (blocked()) { setTimeout(openCall, 1500); return; } // wait until the lesson, attack or conversation is over
    if (!call) {
      call = document.createElement('section');
      call.className = 'phone-pop';
      call.setAttribute('role', 'dialog');
      call.setAttribute('aria-label', 'Message from Mira, Lumen Capital');
      document.querySelector('.stage').append(call);
      call.addEventListener('click', onCallClick);
    }
    try {
      renderCall(await api.phone());
      if (unread) { await api.readTexts(); apply({ ...store.state.events, unread: 0 }); }
    } catch (err) {
      store.log('error', err.message);
    }
  }
  function closeCall() { call?.remove(); call = null; }

  function renderCall(d, note = '') {
    if (!call) return;
    const o = d.offer;
    if (o) since = o.since;
    // this conversation with the investor, oldest first
    const thread = d.messages.filter((m) => (m.sender === 'investor' || m.sender === 'you') && (!since || m.at >= since)).slice(0, 8).reverse()
      .map((m) => `<li class="${m.sender === 'you' ? 'me' : 'them'}">${esc(m.body)}</li>`).join('');
    call.innerHTML = `
      <header><div class="pp-face"></div><div><b>Mira</b><small>Lumen Capital</small></div>
        <button type="button" class="pp-x" data-later aria-label="Close the phone (the offer stays open)"><i class="ti ti-x" aria-hidden="true"></i></button></header>
      <ul class="pp-thread">${thread}</ul>
      ${note ? `<p class="pp-note">${esc(note)}</p>` : ''}
      ${o ? `<div class="pp-actions">
          <button type="button" class="pp-btn primary" data-answer="accept">Accept ${esc(fmt(o.amount))}</button>
          <button type="button" class="pp-btn" data-answer="more" data-tip="She raises the offer 20-40%, or walks away. The bigger your company, the less she needs the deal.">Ask for more <small>${o.walkPct}% she walks</small></button>
          <button type="button" class="pp-btn ghost" data-answer="decline">No thanks</button>
        </div><p class="pp-timer mono">Offer open for ${Math.max(1, Math.ceil(o.secondsLeft / 60))} min</p>`
        : '<div class="pp-actions"><button type="button" class="pp-btn" data-later>Close</button></div>'}`;
    const face = call.querySelector('.pp-face');
    loadStory().then((story) => face.append(portraitEl(character(story, 'investor'), 2))).catch(() => {});
    const t = call.querySelector('.pp-thread');
    t.scrollTop = t.scrollHeight;
  }

  async function onCallClick(e) {
    const b = e.target.closest('button');
    if (!b || callBusy) return;
    if (b.dataset.later !== undefined) { closeCall(); return; }
    const choice = b.dataset.answer;
    if (!choice || offerId === null) return;
    callBusy = true;
    call.querySelectorAll('button').forEach((x) => { x.disabled = true; });
    try {
      const r = await api.answerInvestor(offerId, choice);
      const { outcome, amount } = r.result;
      if (outcome !== 'raised') offerId = null;
      apply(r.poll);
      if (outcome === 'invested') { await refreshState(); }
      renderCall(r.phone, outcome === 'invested' ? `+${fmt(amount)} credits` : outcome === 'walked' ? 'She walked away.' : '');
      if (outcome === 'invested' || outcome === 'walked' || outcome === 'declined') setTimeout(() => { if (offerId === null) closeCall(); }, 2600);
    } catch (err) {
      store.log('error', err.message);
      closeCall();
    } finally {
      callBusy = false;
    }
  }

  async function refreshState() {
    try { store.applyServerState(await api.state()); } catch { /* the next poll catches up */ }
  }

  function apply(d) {
    if (!d) return;
    if (d !== store.state.events) store.set({ events: d });
    const ev = d.event;
    if (ev?.kind === 'broker') {
      const fresh = broker !== ev.id;
      broker = ev.id;
      if (store.scene?.player && !store.scene.npc) store.scene.showNpc(); // the map may still be starting up
      if (fresh && !greeted.has(ev.id)) {
        // like Ghost: he speaks up himself once he's caught up with you
        greeted.add(ev.id);
        setTimeout(() => { if (broker === ev.id) store.npcClicked(); }, 2500);
      }
    } else if (ev?.kind !== 'broker' && broker !== null) {
      broker = null;
      store.scene?.hideNpc();
    }
    // an investor's offer: ring, notify, then the phone pops up with it
    if (d.offer && d.offer.id !== offerId) {
      offerId = d.offer.id;
      ring();
      notify('New message from Mira, Lumen Capital', `Offering ${fmt(d.offer.amount)} credits for a stake in your company.`);
      setTimeout(() => openCall(), 1600);
    } else if (!d.offer && offerId !== null) {
      offerId = null;
      if (call && !callBusy) closeCall();
    }
    if (unread !== null && d.unread > unread) ring();
    unread = d.unread;
    const badge = btn.querySelector('.badge');
    badge.textContent = d.unread;
    badge.hidden = !d.unread;
    btn.setAttribute('aria-label', d.unread ? `Phone, ${d.unread} new text${d.unread === 1 ? '' : 's'}` : 'Phone: texts and your company value');
  }

  async function poll() {
    if (document.hidden) return;
    const s = store.state;
    const mayStart = !!store.tutorialDone && !s.learnOpen && !s.attackOpen && !s.socialOpen;
    try { apply(await api.events(mayStart)); } catch { /* offline for a moment */ }
  }

  /* ---------- the broker ---------- */
  store.npcClicked = async () => {
    if (talking || broker === null || !store.talk) return;
    talking = true;
    const id = broker;
    try {
      const choice = await store.talk('broker', {}, {
        choices: [{ label: 'Take his help (risky)', value: 'accept' }, { label: 'Not now', value: 'later' }, { label: 'Get lost', value: 'refuse' }],
      });
      if (choice !== 'accept' && choice !== 'refuse') {
        store.log('info', 'Vex is still following you. Click him if you change your mind.');
        return; // he keeps following
      }
      const r = await api.answerBroker(id, choice);
      apply(r.poll);
      await refreshState();
      const { outcome, pct, minutes, amount } = r.result;
      await store.talk(`broker:${outcome}`, { pct, minutes, amount: fmt(amount) });
      if (outcome === 'deal') { store.setTab('shop'); store.set({ shopGroup: 'gpu' }); }
    } catch (err) {
      store.log('error', err.message);
    } finally {
      talking = false;
    }
  };

  /* ---------- the phone ---------- */
  const close = () => { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); store.set({ phoneOpen: false }); };
  btn.addEventListener('click', async (e) => {
    e.stopPropagation();
    if (!panel.hidden) { close(); return; }
    panel.hidden = false;
    btn.setAttribute('aria-expanded', 'true');
    store.set({ phoneOpen: true });
    panel.innerHTML = '<p class="ph-note">Loading…</p>';
    try {
      const d = await api.phone();
      renderPhone(d);
      if (d.messages.some((m) => !m.read)) { await api.readTexts(); apply({ ...store.state.events, unread: 0 }); }
    } catch (err) {
      panel.innerHTML = `<p class="ph-note">${esc(err.message)}</p>`;
    }
  });
  document.addEventListener('click', (e) => { if (!panel.hidden && !panel.contains(e.target)) close(); });
  addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) close(); });

  function renderPhone(d) {
    const h = d.history;
    const first = h[0]?.value, last = d.value;
    const ch = first ? Math.round(((last - first) / first) * 100) : 0;
    const chip = !first ? '' : `<span class="ph-chg ${ch >= 0 ? 'up' : 'down'}">${ch >= 0 ? '▲' : '▼'} ${Math.abs(ch)}%</span>`;
    const texts = d.messages.length
      ? d.messages.map((m) => `<li class="${m.read ? '' : 'new'}"><div class="ph-from"><b>${m.sender === 'investor' ? 'Mira, Lumen Capital' : esc(m.sender)}</b><time>${esc(when(m.at))}</time></div>
          <p>${esc(m.body)}</p>${m.amount > 0 ? `<span class="ph-amt mono">+${fmt(m.amount)} credits</span>` : ''}</li>`).join('')
      : `<li class="empty">${d.eligible ? 'No texts yet. Investors get in touch while your company grows.' : 'Lease the office to go public: investors will start texting you.'}</li>`;
    panel.innerHTML = `
      <div class="ph-head"><small>Company value</small><b class="mono">${fmt(last)}</b>${chip}</div>
      <div class="ph-chart" role="img" aria-label="${esc(chartLabel(h, last))}">${chart(h)}</div>
      <p class="ph-note">Value is an hour of income, lifted by your level and security rating, plus a slice of your savings.</p>
      <h4>Texts</h4><ul class="ph-texts">${texts}</ul>`;
    hover(panel.querySelector('.ph-chart'), h);
  }

  setInterval(poll, POLL_MS);
  poll();
  apply(store.state.events);
  // once the map is up, bring in a broker who's already here
  const wait = setInterval(() => { if (store.scene?.player) { clearInterval(wait); apply(store.state.events); } }, 300);
}

function when(at) {
  const t = new Date(at.replace(' ', 'T'));
  return isNaN(t) ? at : t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function chartLabel(h, last) {
  if (h.length < 2) return `Company value ${fmt(last)} credits. The chart fills in as you play.`;
  return `Company value over the last ${h.length} snapshots, from ${fmt(h[0].value)} to ${fmt(h[h.length - 1].value)} credits.`;
}

const CW = 260, CH = 90, PAD = { l: 4, r: 4, t: 8, b: 14 };

/** The company value as one line: thin, with the latest point marked. */
function chart(h) {
  if (h.length < 2) return '<p class="ph-empty">The chart fills in as you play: a point every 10 minutes.</p>';
  const vals = h.map((p) => p.value);
  let lo = Math.min(...vals), hi = Math.max(...vals);
  if (hi === lo) { hi += 1; lo = Math.max(0, lo - 1); }
  const x = (i) => PAD.l + (i / (h.length - 1)) * (CW - PAD.l - PAD.r);
  const y = (v) => PAD.t + (1 - (v - lo) / (hi - lo)) * (CH - PAD.t - PAD.b);
  const pts = h.map((p, i) => `${x(i).toFixed(1)},${y(p.value).toFixed(1)}`).join(' ');
  const lx = x(h.length - 1), ly = y(vals[vals.length - 1]);
  return `<svg viewBox="0 0 ${CW} ${CH}" preserveAspectRatio="none" aria-hidden="true">
    <line class="ph-grid" x1="${PAD.l}" x2="${CW - PAD.r}" y1="${CH - PAD.b}" y2="${CH - PAD.b}"/>
    <polyline class="ph-line" points="${pts}"/>
    <circle class="ph-dot" cx="${lx}" cy="${ly}" r="3"/>
    <line class="ph-cross" x1="0" x2="0" y1="${PAD.t}" y2="${CH - PAD.b}" visibility="hidden"/>
    <circle class="ph-hov" r="3" visibility="hidden"/>
    <text class="ph-ax" x="${PAD.l}" y="${CH - 2}">${esc(when(h[0].at))}</text>
    <text class="ph-ax" x="${CW - PAD.r}" y="${CH - 2}" text-anchor="end">${esc(when(h[h.length - 1].at))}</text>
  </svg><div class="ph-tip" hidden></div>`;
}

/** Crosshair and tooltip: the nearest snapshot to the pointer. */
function hover(box, h) {
  const svg = box?.querySelector('svg');
  if (!svg || h.length < 2) return;
  const tip = box.querySelector('.ph-tip'), cross = svg.querySelector('.ph-cross'), dot = svg.querySelector('.ph-hov');
  const vals = h.map((p) => p.value);
  let lo = Math.min(...vals), hi = Math.max(...vals);
  if (hi === lo) { hi += 1; lo = Math.max(0, lo - 1); }
  const move = (e) => {
    const r = svg.getBoundingClientRect();
    const f = Math.min(1, Math.max(0, (e.clientX - r.left) / r.width));
    const i = Math.round(f * (h.length - 1));
    const sx = PAD.l + (i / (h.length - 1)) * (CW - PAD.l - PAD.r);
    const sy = PAD.t + (1 - (h[i].value - lo) / (hi - lo)) * (CH - PAD.t - PAD.b);
    cross.setAttribute('x1', sx); cross.setAttribute('x2', sx); cross.setAttribute('visibility', 'visible');
    dot.setAttribute('cx', sx); dot.setAttribute('cy', sy); dot.setAttribute('visibility', 'visible');
    tip.hidden = false;
    tip.innerHTML = `<b class="mono">${fmt(h[i].value)}</b><small>${esc(when(h[i].at))}</small>`;
    const px = (sx / CW) * r.width;
    tip.style.left = `${Math.min(Math.max(px, 40), r.width - 40)}px`;
  };
  box.addEventListener('pointermove', move);
  box.addEventListener('pointerleave', () => { tip.hidden = true; cross.setAttribute('visibility', 'hidden'); dot.setAttribute('visibility', 'hidden'); });
}
