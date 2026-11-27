// The Staff panel: resumes to hire or reject, and the team you've hired.
// Hiring is only possible in the office and the datacentre. Everything is decided on the server
// (src/Staff.php); this file shows it and sends hire, reject and fire.
import { api } from './api.js';
import { rate } from './money.js';
import { $, esc } from './util.js';

const ROLE_ICON = { earner: 'ti-device-desktop', admin: 'ti-router', technician: 'ti-tool', security: 'ti-shield-lock' };
const ROLE_WHY = {
  earner: 'Earners work the desktops. A desktop in the office or datacentre earns nothing without one.',
  admin: 'Network admins tune the network, so everything in their place earns more.',
  technician: 'Technicians look after the gear, so it runs cooler and you need less cooling.',
  security: 'Security analysts watch for attackers, so a breach takes less of your credits.',
};
const PLACE = { office: 'Office', datacentre: 'Datacentre' };

export function mountStaff(store) {
  const panel = $('staff');
  const view = $('staff-view');
  let tab = 'resumes';
  let confirmFire = null; // staff id waiting for a second click
  let drawn = null;

  function open(which) {
    window.dispatchEvent(new CustomEvent('nf:open-staff'));
    if (store.state.learnOpen) { store.log('warn', 'Finish or quit the questions in Learn first.'); return; }
    tab = which || tab;
    panel.hidden = false;
    store.set({ staffOpen: true, uiFocus: true, selectedItem: null, picker: null });
    drawn = null;
    render(store.state);
    $('staff-close').focus();
  }
  function close() {
    if (panel.hidden) return;
    panel.hidden = true;
    confirmFire = null;
    store.set({ staffOpen: false, uiFocus: !!(store.state.learnOpen || store.state.socialOpen) });
  }
  store.openStaff = open;

  $('staff-close').addEventListener('click', close);
  document.querySelectorAll('[data-open-staff]').forEach((b) => b.addEventListener('click', () => (panel.hidden ? open() : close())));
  window.addEventListener('nf:open-learn', close);
  window.addEventListener('nf:open-social', close);
  addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden && store.state.termOpen !== true) { e.preventDefault(); close(); } });
  $('staff-tabs').addEventListener('click', (e) => {
    const b = e.target.closest('[data-tab-staff]');
    if (b) { tab = b.dataset.tabStaff; confirmFire = null; drawn = null; render(store.state); }
  });

  view.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-hire], [data-reject], [data-fire]');
    if (!b) return;
    if (b.getAttribute('aria-disabled') === 'true') { store.log('warn', b.dataset.tip || 'You can’t do that here.'); return; }
    if (b.dataset.fire && confirmFire !== Number(b.dataset.fire)) { confirmFire = Number(b.dataset.fire); drawn = null; render(store.state); return; }
    const [action, id] = b.dataset.hire ? ['hire', b.dataset.hire] : b.dataset.reject ? ['reject', b.dataset.reject] : ['fire', b.dataset.fire];
    await store.busyCall(async () => {
      const res = await api.staff(action, Number(id));
      confirmFire = null;
      store.applyServerState(res.state);
      store.set({ lastEvent: { type: action === 'hire' ? 'hired' : action, at: Date.now() } });
    });
  });

  store.subscribe(render);

  function render(s) {
    if (panel.hidden || !s.staff) return;
    if (drawn && drawn.staff === s.staff && drawn.tab === tab && drawn.busy === s.busy) return;
    drawn = { staff: s.staff, tab, busy: s.busy };
    $('staff-tabs').querySelectorAll('[data-tab-staff]').forEach((b) => b.classList.toggle('on', b.dataset.tabStaff === tab));
    const team = $('staff-tabs').querySelector('[data-tab-staff="team"] small');
    team.textContent = s.staff.team.length ? `${s.staff.team.length} hired, ${rate(s.staff.team.reduce((a, m) => a + m.paySec, 0))} in pay` : 'No one yet';
    view.innerHTML = tab === 'team' ? teamView(s) : resumesView(s);
  }

  function resumesView(s) {
    const st = s.staff;
    const here = st.team.filter((m) => m.location === s.user.location).length;
    const why = !st.canHire
      ? ((s.locations || []).some((l) => l.leased && PLACE[l.code])
        ? 'You can only hire in the office or the datacentre. Go there from the place menu at the top.'
        : 'You can only hire in the office or the datacentre. Lease one from the place menu at the top, then go there.')
      : here >= st.max ? `This place is full: ${st.max} staff at most. Let someone go in Your team first.` : '';
    const head = st.canHire
      ? `<p class="s-lead">Hiring for the <b>${esc(PLACE[s.user.location])}</b>: ${here} of ${st.max} places taken. Read each resume, then hire or reject. A new one arrives either way.</p>`
      : `<p class="s-lead warn"><i class="ti ti-home" aria-hidden="true"></i>You’re in the garage, where you work the first desktop yourself. Go to the office or the datacentre to hire.</p>`;
    return `<h2 class="s-h">Resumes</h2>${head}<div class="cv-list">${st.candidates.map((c) => cv(c, why, s.busy)).join('')}</div>
      <p class="s-foot">Pay comes out of your income every second, so a hire only pays off if they add more than they take.</p>`;
  }

  function cv(c, why, busy) {
    const r = c.resume || {};
    const off = why ? `aria-disabled="true" data-tip="${esc(why)}"` : busy ? 'aria-disabled="true"' : '';
    return `<article class="cv role-${esc(c.role)}">
      <header><i class="ti ${ROLE_ICON[c.role]} cv-icon" aria-hidden="true"></i>
        <div><h3>${esc(c.name)}</h3><p>${esc(c.levelName)} ${esc(c.roleName.toLowerCase())}</p></div>
        <span class="cv-lvl mono" title="Seniority">${'●'.repeat(c.level)}${'○'.repeat(5 - c.level)}</span></header>
      <dl>
        <dt>Experience</dt><dd>${esc(r.years)} years, last at ${esc(r.previous)}</dd>
        <dt>Certifications</dt><dd>${(r.certs || []).map(esc).join(', ') || 'None'}</dd>
        <dt>Skills</dt><dd><ul>${(r.skills || []).map((k) => `<li>${esc(k)}</li>`).join('')}</ul></dd>
      </dl>
      <div class="cv-deal"><p class="does" data-tip="${esc(ROLE_WHY[c.role])}"><i class="ti ti-arrow-up-right" aria-hidden="true"></i>${esc(c.does)}</p>
        <p class="costs"><i class="ti ti-coins" aria-hidden="true"></i>${esc(c.costs)}</p></div>
      <footer><button class="act" data-reject="${c.id}" ${busy ? 'aria-disabled="true"' : ''}><i class="ti ti-x" aria-hidden="true"></i>Reject</button>
        <button class="act up" data-hire="${c.id}" ${off}><i class="ti ti-check" aria-hidden="true"></i>Hire</button></footer>
    </article>`;
  }

  function teamView(s) {
    const st = s.staff;
    if (!st.team.length) {
      return `<h2 class="s-h">Your team</h2><p class="s-lead">No one works for you yet. Hire from the resumes in the office or the datacentre.</p>`;
    }
    const pay = st.team.reduce((a, m) => a + m.paySec, 0);
    const places = ['office', 'datacentre'].filter((p) => st.team.some((m) => m.location === p));
    return `<h2 class="s-h">Your team</h2><p class="s-lead">${st.team.length} staff, taking <b>${rate(pay)}</b> of your income right now.</p>` + places.map((p) => {
      const list = st.team.filter((m) => m.location === p);
      return `<h3 class="s-place">${esc(PLACE[p])} <span>${list.length} of ${p === 'office' ? 8 : 16}</span></h3><ul class="team">${list.map((m) => `<li class="role-${esc(m.role)}">
        <i class="ti ${ROLE_ICON[m.role]}" aria-hidden="true"></i>
        <div class="who"><b>${esc(m.name)}</b><span>${esc(m.levelName)} ${esc(m.roleName.toLowerCase())}</span></div>
        <div class="what"><span>${esc(m.does)}</span><span class="mono">${m.paySec > 0 ? `paid ${rate(m.paySec)}` : m.role === 'earner' ? 'paid only while their desktop earns' : 'paid only while your network earns'}</span></div>
        <button class="act ${confirmFire === m.id ? 'danger' : ''}" data-fire="${m.id}" ${s.busy ? 'aria-disabled="true"' : ''}>${confirmFire === m.id ? 'Sure? Let go' : 'Let go'}</button>
      </li>`).join('')}</ul>`;
    }).join('') + '<p class="s-foot">Earners take the desktops in their place, most senior first. One with no desktop costs nothing but does nothing.</p>';
  }
}
