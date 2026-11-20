// Cyber attacks (module 5.0): watches for incoming attacks, runs the timed defence,
// shows the security rating and the attack history. Marking happens on the server.

import { loadStory, character, fill } from './story.js';
import { portraitEl } from './dialogue.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const POLL_MS = 15000;
const ICON = { phishing: 'ti-mail-exclamation', port_scan: 'ti-radar-2', arp_spoof: 'ti-arrows-exchange', syn_flood: 'ti-wave-sine' };

async function call(method, body, query = '') {
  const res = await fetch(`api/defence.php${query}`, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NF.csrf },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({ ok: false, error: 'The server sent an unreadable reply.' }));
  if (!data.ok) throw new Error(data.error || 'That didn’t work.');
  return data;
}

export function mountDefence(store) {
  const box = $('attack');
  const chip = $('security');
  const map = $('map');
  let current = null;   // attack on screen
  let timer = null;
  let sending = false;

  function setOpen(open) {
    box.hidden = !open;
    map.classList.toggle('under-attack', open && !!current);
    store.set({ attackOpen: open, uiFocus: open || !!store.state.learnOpen || !!store.state.socialOpen });
  }

  function showSecurity(sec) {
    chip.querySelector('.grade').textContent = sec ? sec.grade : '–';
    chip.querySelector('.pct').textContent = sec ? `${sec.pct}%` : 'no attacks yet';
    chip.className = `security g-${sec ? sec.grade : 'none'}`;
    chip.setAttribute('aria-label', sec ? `Security rating ${sec.grade}, ${sec.pct}%. Open attack history.` : 'No attacks yet. Open attack history.');
  }

  async function poll() {
    if (current || document.hidden) return;
    try {
      const d = await call('GET');
      showSecurity(d.security);
      if (d.attack) showAttack(d.attack);
    } catch { /* offline for a moment: try again next tick */ }
  }

  /* ---------- the attack ---------- */

  function showAttack(a) {
    current = a;
    const evidence = a.evidence.split('\n').map((l) => esc(l) || '&nbsp;').join('\n');
    box.innerHTML = `
      <div class="atk-card">
        <header class="atk-head">
          <i class="ti ${ICON[a.type] || 'ti-shield-x'}" aria-hidden="true"></i>
          <div><h2 id="atk-title">Incoming attack: ${esc(a.name)}</h2><p class="atk-sub">${esc(a.title)}</p></div>
          <span class="atk-clock mono" id="atk-clock" aria-live="off"></span>
        </header>
        <div class="atk-taunt" id="atk-taunt" hidden></div>
        <p class="atk-sum">${esc(a.summary)}</p>
        <pre class="atk-evidence" aria-label="Evidence">${evidence}</pre>
        <fieldset class="atk-q"><legend>${esc(a.prompt)}</legend>
          ${a.options.map((o, i) => `<label class="atk-opt"><input type="radio" name="atk" value="${i}"><span class="k mono">${i + 1}</span><span>${esc(o)}</span></label>`).join('')}
        </fieldset>
        <p class="atk-err" id="atk-err" role="alert"></p>
        <div class="atk-actions"><button class="btn primary" id="atk-go">Defend <kbd>Enter</kbd></button></div>
      </div>`;
    box.setAttribute('aria-labelledby', 'atk-title');
    setOpen(true);
    box.querySelector('input').focus();
    $('atk-go').addEventListener('click', () => submit(false));
    startClock(a.secondsLeft);
    taunt();
  }

  /** The hacker shows up with a line of trash talk (the character and lines staff can change). */
  async function taunt() {
    try {
      const story = await loadStory();
      const lines = story.script.attack || [];
      const el = $('atk-taunt');
      if (!el || !lines.length) return;
      const [code, text] = lines[Math.floor(Math.random() * lines.length)];
      const c = character(story, code);
      el.innerHTML = `<div class="atk-face"></div><p><b>${esc(c.name)}</b> ${esc(fill(story, text, store.state.user?.name || 'you'))}</p>`;
      el.querySelector('.atk-face').append(portraitEl(c, 2));
      el.hidden = false;
    } catch { /* no story data: the attack works without the taunt */ }
  }

  function startClock(seconds) {
    stopClock();
    const end = Date.now() + seconds * 1000;
    const tick = () => {
      const left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
      const el = $('atk-clock');
      if (el) { el.textContent = `0:${String(left).padStart(2, '0')}`; el.classList.toggle('low', left <= 10); }
      if (left === 0) { stopClock(); setTimeout(() => submit(true), 2500); } // past the server's grace
    };
    tick();
    timer = setInterval(tick, 250);
  }
  function stopClock() { if (timer) clearInterval(timer); timer = null; }

  async function submit(timeUp) {
    if (sending || !current) return;
    const picked = box.querySelector('input[name=atk]:checked');
    if (!picked && !timeUp) { $('atk-err').textContent = 'Pick a response first.'; return; }
    sending = true;
    stopClock();
    try {
      const { result } = await call('POST', { attack: current.id, choice: picked ? Number(picked.value) : -1 });
      showResult(result);
      store.load(); // credits and console
    } catch (err) {
      const el = $('atk-err');
      if (el) el.textContent = err.message;
    } finally {
      sending = false;
    }
  }

  function showResult(r) {
    const ok = r.status === 'defended';
    current = null;
    map.classList.remove('under-attack');
    box.innerHTML = `
      <div class="atk-card ${ok ? 'won' : 'lost'}">
        <header class="atk-head"><i class="ti ${ok ? 'ti-shield-check' : 'ti-shield-x'}" aria-hidden="true"></i>
          <div><h2 id="atk-title">${ok ? 'Attack stopped' : r.late ? 'Too late: the attack got through' : 'The attack got through'}</h2>
          <p class="atk-sub">${ok ? 'Your network is safe.' : 'Your network was breached.'}</p></div></header>
        ${ok ? '' : `<p>The right response: <b>${esc(r.correct)}</b></p>`}
        <p class="atk-why">${esc(r.explanation)}</p>
        <p class="mono atk-meta">${r.penalty ? `−${r.penalty} credits · ` : ''}Security rating ${r.security ? `${r.security.grade} (${r.security.pct}%)` : '–'}</p>
        <div class="atk-actions"><button class="btn primary" id="atk-close">Back to the ${esc((store.state.user?.locationName || 'garage').toLowerCase())}</button></div>
      </div>`;
    showSecurity(r.security);
    $('atk-close').addEventListener('click', () => setOpen(false));
    $('atk-close').focus();
  }

  /* ---------- history ---------- */

  async function showHistory() {
    if (current) return; // finish the attack first
    try {
      const d = await call('GET', null, '?history=1');
      showSecurity(d.security);
      const rows = d.history.length ? d.history.map((h) => `
        <li class="${h.status}">
          <div class="h-top"><span class="h-out"><i class="ti ${h.status === 'defended' ? 'ti-shield-check' : 'ti-shield-x'}" aria-hidden="true"></i>${h.status === 'defended' ? 'Defended' : 'Breached'}</span>
            <strong>${esc(h.name)}: ${esc(h.title)}</strong><time class="mono">${esc(h.at.slice(0, 16))}</time></div>
          <details><summary>Review</summary>
            <p>${esc(h.prompt)}</p>
            <p>Your response: ${h.yours ? esc(h.yours) : '<i>no response in time</i>'}</p>
            ${h.status === 'defended' ? '' : `<p>Right response: <b>${esc(h.correct)}</b></p>`}
            <p class="atk-why">${esc(h.explanation)}</p>
            ${h.penalty ? `<p class="mono">−${h.penalty} credits</p>` : ''}
          </details></li>`).join('')
        : '<li class="empty">No attacks yet. Once you master a world, attacks built from its topics can hit your network.</li>';
      box.innerHTML = `
        <div class="atk-card hist">
          <header class="atk-head"><i class="ti ti-shield-half" aria-hidden="true"></i>
            <div><h2 id="atk-title">Attack history</h2><p class="atk-sub">Security rating</p></div>
            <span class="atk-grade mono">${d.security ? `${d.security.grade} · ${d.security.pct}%` : '–'}</span></header>
          <p class="atk-sum">Your rating weights your last ${10} defences, newest counting most.</p>
          <ul class="hist-list">${rows}</ul>
          <div class="atk-actions"><button class="btn" id="atk-close">Close</button></div>
        </div>`;
      setOpen(true);
      $('atk-close').addEventListener('click', () => setOpen(false));
      $('atk-close').focus();
    } catch (err) {
      store.log('error', err.message);
    }
  }

  chip.addEventListener('click', showHistory);
  box.addEventListener('keydown', (e) => {
    if (current && e.key === 'Enter') { e.preventDefault(); submit(false); }
    if (current && /^[1-4]$/.test(e.key)) {
      const r = box.querySelectorAll('input[name=atk]')[Number(e.key) - 1];
      if (r) { r.checked = true; r.focus(); }
    }
    if (!current && e.key === 'Escape') { e.preventDefault(); setOpen(false); }
  });

  poll();
  setInterval(poll, POLL_MS);
  document.addEventListener('visibilitychange', poll);
}
