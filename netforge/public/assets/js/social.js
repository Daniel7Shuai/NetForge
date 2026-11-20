// The Social panel (module 6.0): guild, weekly raid, leaderboards, friends.
// Everything is decided on the server; this file only shows it and sends actions.
import { rate } from './money.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
const fmtDate = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
const ago = (ts) => {
  if (!ts) return 'never';
  const d = Math.floor((Date.now() - new Date(ts.replace(' ', 'T')).getTime()) / 86400000);
  return d <= 0 ? 'today' : d === 1 ? 'yesterday' : `${d} days ago`;
};
const progress = (value, target, label) => {
  const pct = target ? Math.min(100, Math.round((value / target) * 100)) : 0;
  return `<div class="prog" role="progressbar" aria-valuemin="0" aria-valuemax="${target}" aria-valuenow="${value}" aria-label="${esc(label)}">
    <span class="track"><span class="fill${pct >= 100 ? ' done' : ''}" style="width:${Math.max(pct, 1)}%"></span></span>
    <span class="val mono">${value}/${target}</span></div>`;
};

async function call(body) {
  const res = await fetch('api/social.php', {
    method: body ? 'POST' : 'GET',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NF.csrf },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json().catch(() => ({ ok: false, error: 'The server sent an unreadable reply.' }));
  if (!data.ok) throw new Error(data.error || 'That didn’t work.');
  return data.social;
}

export function mountSocial(store) {
  const panel = $('social');
  const tabs = $('social-tabs');
  const view = $('social-view');
  let data = null;
  let tab = 'guild';

  async function open(which) {
    window.dispatchEvent(new CustomEvent('nf:open-social'));
    tab = which || tab;
    panel.hidden = false;
    store.set({ socialOpen: true, uiFocus: true, selectedItem: null });
    await refresh();
    $('social-close').focus();
  }
  function close() {
    panel.hidden = true;
    store.set({ socialOpen: false, uiFocus: !!store.state.learnOpen });
  }
  async function refresh(body) {
    try {
      data = await call(body);
      render();
      if (body) store.load(); // credits, console log and objective may have changed
    } catch (err) {
      showErr(err.message);
    }
  }

  $('social-close').addEventListener('click', close);
  document.querySelectorAll('[data-open-social]').forEach((b) => b.addEventListener('click', () => (panel.hidden ? open() : close())));
  window.addEventListener('nf:open-learn', () => { if (!panel.hidden) close(); });
  addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); close(); } });
  tabs.addEventListener('click', (e) => {
    const b = e.target.closest('[data-tab-social]');
    if (b) { tab = b.dataset.tabSocial; render(); }
  });

  function showErr(msg) {
    const el = view.querySelector('.s-err');
    if (el) { el.textContent = msg; el.hidden = false; } else store.log('error', msg);
  }

  /* ---------- rendering ---------- */

  function render() {
    if (!data) return; // still loading; refresh() renders when it arrives
    tabs.querySelectorAll('[data-tab-social]').forEach((b) => {
      b.classList.toggle('on', b.dataset.tabSocial === tab);
      b.setAttribute('aria-current', b.dataset.tabSocial === tab ? 'page' : 'false');
    });
    const week = `Week of ${fmtDate(data.week.start)} to ${fmtDate(data.week.end)}`;
    if (tab === 'badges') { renderBadges(week); return; }
    const body = tab === 'board' ? renderBoard() : tab === 'friends' ? renderFriends() : renderGuild();
    view.innerHTML = `<p class="crumb mono">${week}</p>${body}<p class="s-err" role="alert" hidden></p>`;
  }

  function raidCard() {
    const c = data.challenge;
    if (!c) return '<section class="card"><h3><i class="ti ti-swords" aria-hidden="true"></i>Weekly raid</h3><p class="muted">No raid this week. Your lecturer publishes one each week.</p></section>';
    const r = data.guild?.raid;
    return `<section class="card raid${r?.done ? ' won' : ''}">
      <h3><i class="ti ti-swords" aria-hidden="true"></i>Weekly raid: ${esc(c.title)}</h3>
      <p>${esc(c.description)}</p>
      <p class="muted">Every correct answer${c.world ? ` in ${esc(c.world)}` : ''} by a guild member counts.${c.reward ? ` Each contributor earns ${c.reward} XP when your guild hits ${c.target}.` : ''}</p>
      ${r ? progress(r.progress, c.target, 'Raid progress') + `<p class="muted">${r.done ? '<b class="ok-txt">Raid complete.</b> ' : ''}You’ve added ${r.mine}.</p>` : '<p class="muted">Join a guild to take part.</p>'}
    </section>`;
  }

  function renderGuild() {
    const g = data.guild;
    if (!g) {
      const list = data.guilds.length ? data.guilds.map((x) => `
        <li><span class="gtag mono">[${esc(x.tag)}]</span>
          <span class="gname">${esc(x.name)}<small>${esc(x.motto || 'No motto yet')}</small></span>
          <span class="mono muted">${x.members}/${data.maxMembers} · ${x.weekCorrect} this week</span>
          <button type="button" class="btn" data-join="${x.id}" ${x.full ? 'disabled' : ''}>${x.full ? 'Full' : 'Join'}</button></li>`).join('')
        : '<li class="muted">No guilds yet. Start the first one.</li>';
      return `<h2>Guilds</h2>
        <p class="sum">Guilds of up to ${data.maxMembers} students work towards a weekly raid together. Every correct answer you give counts for your guild.</p>
        ${raidCard()}
        <section class="card"><h3>Join a guild</h3><ul class="glist">${list}</ul></section>
        <section class="card"><h3>Start a guild</h3>
          <form class="sform" data-form="create">
            <label>Name <input name="name" maxlength="32" required placeholder="Packet Pushers"></label>
            <label>Tag <input name="tag" maxlength="5" required placeholder="PKT" class="mono"></label>
            <label class="wide">Motto <input name="motto" maxlength="120" placeholder="No packet left behind"></label>
            <button class="btn primary" type="submit">Start guild</button>
          </form></section>`;
    }
    const leader = g.myRole === 'leader';
    const members = g.members.map((m) => `
      <li${m.me ? ' class="me"' : ''}><span class="mname">${esc(m.username)}${m.role === 'leader' ? ' <i class="ti ti-crown" aria-label="Leader"></i>' : ''}</span>
        <span class="mono muted">Lv ${m.level}</span><span class="mono">${m.weekCorrect} correct</span>
        ${leader && !m.me ? `<button type="button" class="mini-btn" data-kick="${m.id}" aria-label="Remove ${esc(m.username)}">Remove</button>` : '<span></span>'}</li>`).join('');
    const goal = g.goal ? `${progress(g.goal.progress, g.goal.target, 'Guild goal progress')}<p class="muted">${esc(g.goal.title)}</p>`
      : `<p class="muted">${leader ? 'Set a goal for the week.' : 'Your leader hasn’t set a goal this week.'}</p>`;
    const ms = Object.entries(data.milestones);
    const feed = g.feed.length ? g.feed.map((f) => `<li class="k-${f.kind}"><span>${esc(f.message)}</span><time class="muted mono">${ago(f.at)}</time></li>`).join('') : '<li class="muted">Nothing yet.</li>';
    return `<header class="ghead"><div><h2><span class="gtag mono">[${esc(g.tag)}]</span> ${esc(g.name)}</h2>
        <p class="sum">${esc(g.motto || '')}</p></div>
        <button type="button" class="btn" data-leave="1">Leave guild</button></header>
      ${raidCard()}
      <div class="cols">
        <section class="card"><h3><i class="ti ti-target" aria-hidden="true"></i>Guild goal</h3>${goal}
          ${leader ? `<form class="sform inline" data-form="goal">
            <label>Goal <input name="title" maxlength="80" required placeholder="Clear Topic 1" value="${esc(g.goal?.title || '')}"></label>
            <label>Correct answers <input name="target" type="number" min="5" max="2000" required value="${g.goal?.target || 50}"></label>
            <button class="btn" type="submit">${g.goal ? 'Update' : 'Set goal'}</button></form>` : ''}
        </section>
        <section class="card"><h3><i class="ti ti-users" aria-hidden="true"></i>Members ${g.members.length}/${data.maxMembers}</h3><ul class="mlist">${members}</ul></section>
      </div>
      <div class="cols">
        <section class="card"><h3><i class="ti ti-speakerphone" aria-hidden="true"></i>Share a milestone</h3>
          ${ms.length ? `<form class="sform inline" data-form="share"><label>Milestone <select name="milestone">${ms.map(([k, v]) => `<option value="${esc(k)}">${esc(v)}</option>`).join('')}</select></label>
            <button class="btn" type="submit">Share</button></form>` : '<p class="muted">Master a topic or level up to earn milestones you can share.</p>'}
        </section>
        <section class="card"><h3><i class="ti ti-news" aria-hidden="true"></i>Guild news</h3><ul class="feed">${feed}</ul></section>
      </div>`;
  }

  function renderBoard() {
    const lb = data.leaderboard;
    const rows = lb.rows.map((r) => `<tr${r.me ? ' class="me"' : ''}><td class="mono">#${r.rank}</td><th scope="row">${r.me ? 'You' : esc(r.username)}</th><td class="mono num">${r.xp} XP</td></tr>`).join('');
    const guilds = data.guildBoard.length ? data.guildBoard.map((g) => `<tr${g.mine ? ' class="me"' : ''}><td class="mono">#${g.rank}</td><th scope="row"><span class="gtag mono">[${esc(g.tag)}]</span> ${esc(g.name)}</th>
        <td class="mono num">${g.members}</td><td class="mono num">${g.correct}</td></tr>`).join('') : '<tr><td colspan="4" class="muted">No guilds yet.</td></tr>';
    return `<h2>Leaderboards</h2>
      <section class="card"><h3>You this week${lb.rank ? `: #${lb.rank}` : ''}</h3>
        <p class="muted">Ranked by XP earned this week. You see only the students just above and below you, not the whole class.</p>
        <table class="board"><tbody>${rows}</tbody></table></section>
      <section class="card"><h3>Guilds this week</h3>
        <table class="board"><thead><tr><th scope="col">Rank</th><th scope="col">Guild</th><th scope="col" class="num">Members</th><th scope="col" class="num">Correct answers</th></tr></thead><tbody>${guilds}</tbody></table></section>`;
  }

  function renderFriends() {
    const list = data.friends.length ? data.friends.map((f) => `
      <li><span class="mname">${esc(f.username)}${f.guild ? ` <span class="gtag mono">[${esc(f.guild)}]</span>` : ''}</span>
        <span class="mono muted">Lv ${f.level}</span><span class="mono muted">${f.mastered} topic${f.mastered === 1 ? '' : 's'} mastered</span>
        <span class="mono">${f.weekXp} XP this week</span><span class="muted">active ${ago(f.last)}</span>
        <button type="button" class="mini-btn" data-unfriend="${f.id}" aria-label="Remove ${esc(f.username)} from friends">Remove</button></li>`).join('')
      : '<li class="muted">No friends yet. Add a classmate by their username.</li>';
    return `<h2>Friends</h2>
      <section class="card"><form class="sform inline" data-form="friend"><label>Username <input name="username" required maxlength="32" placeholder="Classmate’s username" autocomplete="off"></label>
        <button class="btn primary" type="submit">Add friend</button></form></section>
      <section class="card"><ul class="flist">${list}</ul></section>`;
  }

  async function renderBadges(week) {
    view.innerHTML = `<p class="crumb mono">${week}</p><h2>Badges</h2><p class="muted">Loading…</p>`;
    try {
      const res = await fetch('api/badges.php', { credentials: 'same-origin' });
      const d = await res.json();
      if (!d.ok) throw new Error(d.error);
      if (tab !== 'badges') return; // switched away while loading
      const earned = d.badges.filter((b) => b.earnedAt).length;
      const st = d.streak;
      view.innerHTML = `<p class="crumb mono">${week}</p><h2>Badges</h2>
        <div class="cols">
          <section class="card"><h3><i class="ti ti-flame" aria-hidden="true"></i>Learning streak</h3>
            <p class="big-stat mono">${st.current} day${st.current === 1 ? '' : 's'}</p>
            <p class="muted">${st.today ? 'You’ve learned today. ' : 'Answer a question today to keep it going. '}Best: ${st.best} day${st.best === 1 ? '' : 's'}.</p></section>
          <section class="card"><h3><i class="ti ti-trending-up" aria-hidden="true"></i>Network income</h3>
            <p class="big-stat mono">+${rate(d.income.perSec)}</p>
            <p class="muted">${d.income.devices.length ? `Renting out ${esc(d.income.devices.join(', '))}.` : 'Nothing online yet. Link a device to the ISP box to start earning.'}</p></section>
        </div>
        <section class="card"><h3><i class="ti ti-award" aria-hidden="true"></i>${earned} of ${d.badges.length} earned</h3>
          <ul class="badge-grid">${d.badges.map((b) => `
            <li class="${b.earnedAt ? 'got' : 'locked'}" title="${esc(b.description)}">
              <i class="ti ${esc(b.icon)}" aria-hidden="true"></i>
              <strong>${esc(b.name)}</strong><small>${esc(b.description)}</small>
              <span class="mono muted">${b.earnedAt ? fmtDate(b.earnedAt.slice(0, 10)) : 'Not yet'}</span></li>`).join('')}</ul>
          <p class="muted">Share earned badges with your guild from the Guild tab.</p></section>
        <p class="s-err" role="alert" hidden></p>`;
    } catch (err) {
      view.innerHTML = `<p class="crumb mono">${week}</p><h2>Badges</h2><p class="s-err" role="alert">${esc(err.message || 'Couldn’t load badges.')}</p>`;
    }
  }

  /* ---------- actions ---------- */

  view.addEventListener('submit', (e) => {
    e.preventDefault();
    const f = e.target;
    const v = Object.fromEntries(new FormData(f));
    const actions = {
      create: { action: 'create', name: v.name, tag: v.tag, motto: v.motto },
      goal: { action: 'goal', title: v.title, target: Number(v.target) },
      share: { action: 'share', milestone: v.milestone },
      friend: { action: 'friend-add', username: v.username },
    };
    refresh(actions[f.dataset.form]);
  });
  view.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || b.type === 'submit' || b.disabled) return;
    if (b.dataset.join) refresh({ action: 'join', guild: Number(b.dataset.join) });
    else if (b.dataset.leave && confirm('Leave your guild? Your contributions this week stay with it.')) refresh({ action: 'leave' });
    else if (b.dataset.kick) refresh({ action: 'kick', member: Number(b.dataset.kick) });
    else if (b.dataset.unfriend) refresh({ action: 'friend-remove', friend: Number(b.dataset.unfriend) });
  });

  return { open, close };
}
