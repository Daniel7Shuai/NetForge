// The Learn screen (module 3.0): roadmap of topics, lessons, challenge runs and exams.
// It only shows what the server sends; marking, Elo and rewards all happen in PHP.
import { api } from './api.js';
import { fmt } from './money.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
/** Escaped text with `code` shown as inline code (commands in prompts and explanations). */
const rich = (s) => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>');
const TIER_CLASS = { Bronze: 'bronze', Silver: 'silver', Gold: 'gold', Platinum: 'platinum' };
/** What happened to the question in the Daily Security Audit after this answer. */
const AUDIT_NOTE = (a) => {
  if (!a) return '';
  if (a === 'queued') return ' · Added to your Daily Security Audit';
  if (a === 'reset') return ' · Back to the 1-day review';
  if (a === 'cleared') return ' · <span class="ok-txt">Cleared from your audit</span>';
  if (a.startsWith('next:')) return ` · Next review in ${a.slice(5)} days`;
  return '';
};
const TYPE_LABEL = { mcq: 'Multiple choice', matching: 'Matching', ordering: 'Ordering', fill: 'Fill in the blanks', blitz: 'Binary Blitz', subnet: 'Subnet calculator' };

/**
 * Light markdown used in lessons: paragraphs, "- " and "1. " lists, **bold**, `code`,
 * and ``` fenced blocks for device commands. Escapes first.
 */
export function renderLesson(md) {
  const inline = (s) => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  const out = [];
  // pull out fenced blocks first, so blank lines inside them don't split them
  const parts = md.trim().split(/^```[^\n]*\n([\s\S]*?)^```\s*$/m);
  parts.forEach((part, i) => {
    if (i % 2 === 1) { out.push(`<pre class="cli"><code>${esc(part.replace(/\n$/, ''))}</code></pre>`); return; }
    for (const block of part.trim().split(/\n\s*\n/)) {
      if (!block.trim()) continue;
      const lines = block.split('\n').map((l) => l.trim());
      if (lines.every((l) => l.startsWith('- '))) {
        out.push(`<ul>${lines.map((l) => `<li>${inline(l.slice(2))}</li>`).join('')}</ul>`);
      } else if (lines.every((l) => /^\d+\.\s/.test(l))) {
        out.push(`<ol>${lines.map((l) => `<li>${inline(l.replace(/^\d+\.\s+/, ''))}</li>`).join('')}</ol>`);
      } else {
        out.push(`<p>${inline(lines.join(' '))}</p>`);
      }
    }
  });
  return out.join('');
}

const tierChip = (tier, rating) => `<span class="tier ${TIER_CLASS[tier]}" title="Elo rating ${rating}">${tier} <b class="mono">${rating}</b></span>`;

export function mountLearn(store) {
  const panel = $('learn');
  const list = $('learn-worlds');
  const view = $('learn-view');
  let worlds = [];
  let audit = { due: 0, total: 0, stages: {}, upcoming: [], topics: [] };
  let mock = { worlds: [], lengths: [10, 20, 30], secondsPerQuestion: 60, readyPct: 70, history: [] };
  const mockPick = { worlds: new Set(), length: 10 }; // the student's choices, kept while Learn is open
  let worldId = null;     // world on screen, or 'audit'
  let run = null;          // {run, question}
  let timer = null;

  /* ---------- open / close ---------- */

  window.addEventListener('nf:open-social', () => { if (!panel.hidden && !run) { stopTimer(); panel.hidden = true; store.setLearnOpen(false); } });

  async function open(focusWorld) {
    window.dispatchEvent(new CustomEvent('nf:open-learn'));
    store.setLearnOpen(true);
    panel.hidden = false;
    await loadRoadmap();
    if (!focusWorld && audit.due > 0) {
      showAudit();
    } else {
      const w = worlds.find((x) => x.id === focusWorld) || worlds.find((x) => x.status === 'open') || worlds[0];
      showWorld(w.id);
    }
    $('learn-close').focus();
  }

  async function close() {
    if (run && !confirm('Leave this challenge? Answers so far are kept, but the run won’t count as finished.')) return;
    if (run) { await api.learn.quit().catch(() => {}); run = null; }
    stopTimer();
    panel.hidden = true;
    store.setLearnOpen(false);
  }

  $('learn-close').addEventListener('click', close);
  document.querySelectorAll('[data-open-learn]').forEach((b) => b.addEventListener('click', () => (panel.hidden ? open() : close())));
  addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !panel.hidden) { e.preventDefault(); close(); }
  });

  /* ---------- roadmap ---------- */

  async function loadRoadmap() {
    const r = await api.learn.roadmap();
    worlds = r.worlds;
    audit = r.audit;
    mock = r.mock || mock;
    const ids = new Set(mock.worlds.map((w) => w.id));
    mockPick.worlds = new Set([...mockPick.worlds].filter((id) => ids.has(id)));
    if (!mockPick.worlds.size) mock.worlds.forEach((w) => mockPick.worlds.add(w.id)); // default: everything open
    renderList();
  }

  function renderList() {
    const auditRow = `<button class="world audit-row${worldId === 'audit' ? ' on' : ''}${audit.due ? ' due' : ''}" data-audit="1">
        <i class="ti ti-shield-check wnum" aria-hidden="true"></i>
        <span class="wtitle">Daily Security Audit<small>${audit.due ? `${audit.due} due today` : audit.total ? 'Nothing due today' : 'No mistakes to review'}</small></span>
        ${audit.due ? `<span class="due-badge mono">${audit.due}</span>` : '<i class="ti ti-circle-check" aria-hidden="true"></i>'}</button>`;
    const last = mock.history[0];
    const mockRow = `<button class="world audit-row mock-row${worldId === 'mock' ? ' on' : ''}" data-mock="1">
        <i class="ti ti-clipboard-check wnum" aria-hidden="true"></i>
        <span class="wtitle">Mock exam<small>${last ? `Last score ${last.pct}%` : 'Timed, from the topics you pick'}</small></span>
        <i class="ti ti-chevron-right" aria-hidden="true"></i></button>`;
    list.innerHTML = auditRow + mockRow + worlds.map((w) => {
      const icon = { mastered: 'ti-circle-check', open: 'ti-player-play', locked: 'ti-lock', area: 'ti-building', soon: 'ti-clock' }[w.status];
      const meta = w.status === 'soon' ? 'Coming soon' : w.status === 'locked' ? 'Locked' : w.status === 'area' ? `Lease the ${w.needs.name.toLowerCase()}` : w.tier;
      return `<button class="world ${w.status}${w.id === worldId ? ' on' : ''}" data-world="${w.id}" ${w.status === 'soon' ? 'aria-disabled="true"' : ''}>
        <span class="wnum mono">T${w.number}</span>
        <span class="wtitle">${esc(w.title)}<small>${meta}</small></span>
        <i class="ti ${icon}" aria-hidden="true"></i></button>`;
    }).join('');
  }
  list.addEventListener('click', (e) => {
    if (run) return;
    if (e.target.closest('[data-audit]')) { showAudit(); return; }
    if (e.target.closest('[data-mock]')) { showMock(); return; }
    const b = e.target.closest('[data-world]');
    if (b) showWorld(Number(b.dataset.world));
  });

  const fmtDay = (iso) => {
    const d = new Date(`${iso}T00:00:00`);
    const days = Math.round((d - new Date(new Date().toDateString())) / 86400000);
    return days === 1 ? 'Tomorrow' : `${d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}, in ${days} days`;
  };

  function showAudit() {
    stopTimer();
    worldId = 'audit';
    renderList();
    const s = audit.stages || {};
    const steps = [[1, '1 day'], [2, '3 days'], [3, '7 days']].map(([n, label]) =>
      `<li><span class="sn mono">${s[n] || 0}</span><span>${label}</span></li>`).join('<li class="arrow" aria-hidden="true"><i class="ti ti-arrow-narrow-right"></i></li>');
    const take = Math.min(audit.due, audit.perRun || 10);
    view.innerHTML = `
      <header class="whead"><div>
        <p class="crumb mono">Spaced review</p>
        <h2>Daily Security Audit</h2>
        <p class="sum">Every question you get wrong comes back here after 1 day. Get it right and it returns after 3 days, then 7. Get it right at 7 days and it’s cleared. A miss sends it back to the 1-day step.</p>
      </div></header>
      <div class="audit-now">
        <p class="big-n mono">${audit.due}</p>
        <div><strong>${audit.due ? (audit.due === 1 ? 'question due today' : 'questions due today') : 'Nothing due today'}</strong>
        <small>${audit.due ? `Reviews earn XP like lessons do. ${audit.due > take ? `This round covers ${take}.` : ''}` : audit.total ? 'Come back on the next review day.' : 'Wrong answers in lessons and exams will show up here.'}</small></div>
        <button class="btn primary" data-audit-start="1" ${audit.due ? '' : 'disabled'}>Start review${take ? `, ${take} question${take > 1 ? 's' : ''}` : ''}</button>
      </div>
      <h3 class="sub">In the queue</h3>
      <ol class="steps" aria-label="Questions at each review step">${steps}</ol>
      ${audit.topics.length ? `<h3 class="sub">Due topics</h3><ul class="chips">${audit.topics.map((t) => `<li>${esc(t.topic)} <b class="mono">${t.count}</b></li>`).join('')}</ul>` : ''}
      ${audit.upcoming.length ? `<h3 class="sub">Coming up</h3><ul class="upcoming">${audit.upcoming.slice(0, 5).map((u) => `<li><span>${fmtDay(u.date)}</span><b class="mono">${u.count}</b></li>`).join('')}</ul>` : ''}`;
  }

  const clock = (sec) => `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;

  function showMock() {
    stopTimer();
    worldId = 'mock';
    renderList();
    const chosen = mock.worlds.filter((w) => mockPick.worlds.has(w.id));
    // a paper can't be longer than the chosen worlds' questions, unless one of them generates fresh ones
    const pool = chosen.some((w) => w.generated) ? Infinity : chosen.reduce((sum, w) => sum + w.questions, 0);
    const n = Math.min(mockPick.length, pool), minutes = Math.ceil((n * mock.secondsPerQuestion) / 60);
    const short = chosen.length && n < mockPick.length ? ` ${chosen.length > 1 ? 'These topics only have' : 'This topic only has'} ${n} questions, so that’s the paper.` : '';
    const worldBtns = mock.worlds.map((w) => `<button type="button" class="wpick" data-mock-world="${w.id}" aria-pressed="${mockPick.worlds.has(w.id)}">
        <i class="ti ${mockPick.worlds.has(w.id) ? 'ti-square-check' : 'ti-square'}" aria-hidden="true"></i><span class="mono">T${w.number}</span>${esc(w.title)}</button>`).join('');
    const lenBtns = mock.lengths.map((l) => `<button type="button" role="radio" aria-checked="${l === mockPick.length}" data-mock-len="${l}">${l}</button>`).join('');
    const hist = mock.history.map((h) => `<tr><td>${esc(new Date(h.at.replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }))}</td>
        <td class="mono">${h.worlds.map((x) => `T${x}`).join(' ')}</td><td class="mono">${h.correct}/${h.total}</td>
        <td class="mono">${clock(h.seconds)}${h.timedOut ? ' (time)' : ''}</td><td class="mono pct">${h.pct}%</td></tr>`).join('');
    view.innerHTML = `<header class="whead"><div>
        <p class="crumb mono">Exam practice</p>
        <h2>Mock exam</h2>
        <p class="sum">Questions from the topics you pick, shared evenly, on one clock. Like the real thing, you only see answers at the end, with your score by subtopic. Answers still earn XP and count toward your rating.</p>
      </div></header>
      ${mock.worlds.length ? `<h3 class="sub">Topics</h3><div class="wpicks">${worldBtns}</div>
      <h3 class="sub" id="mock-len">Questions</h3><div class="seg" role="radiogroup" aria-labelledby="mock-len">${lenBtns}</div>
      <div class="exam ready mock-go"><i class="ti ti-clock-hour-4" aria-hidden="true"></i>
        <div><strong>${n} questions, ${minutes} minutes</strong><small>${chosen.length ? `From ${chosen.length > 1 ? `topics ${chosen.slice(0, -1).map((w) => w.number).join(', ')} and ${chosen.at(-1).number}` : `topic ${chosen[0].number}`}. Score ${mock.readyPct}% to count as exam-ready.${short}` : 'Pick at least one topic.'}</small></div>
        <button class="btn primary" data-mock-start="1" ${chosen.length ? '' : 'disabled'}>Start mock exam</button></div>`
        : '<p class="empty">Open a topic in Learn first. Mock exams draw from the topics you can play.</p>'}
      ${hist ? `<h3 class="sub">Past attempts</h3><table class="hist"><thead><tr><th>Date</th><th>Topics</th><th>Right</th><th>Time</th><th>Score</th></tr></thead><tbody>${hist}</tbody></table>` : ''}`;
  }

  function showWorld(id) {
    stopTimer();
    worldId = id;
    renderList();
    const w = worlds.find((x) => x.id === id);
    const locked = w.status === 'locked' || w.status === 'soon' || w.status === 'area';
    const levels = w.levels.map((l) => `
      <li class="lvl${l.completed ? ' done' : ''}">
        <span class="lnum mono">${w.number}.${l.number}</span>
        <span class="ltitle">${esc(l.title)}<small>${l.questions} questions${l.bestPct !== null ? `, best ${l.bestPct}%` : ''}</small></span>
        ${l.completed ? '<i class="ti ti-circle-check ok" aria-label="Complete"></i>' : ''}
        <button class="btn" data-lesson="${l.id}" ${locked ? 'disabled' : ''}>${l.completed ? 'Review' : 'Start'}</button>
      </li>`).join('');
    const ex = w.exam;
    const examNote = w.status === 'mastered' ? `Mastered. Best score ${ex.best}%.`
      : ex.ready ? `${ex.questions} questions, ${ex.minutes} minutes, pass with ${ex.passPct}%.${ex.best !== null ? ` Best so far ${ex.best}%.` : ''}`
      : 'Finish every level to unlock the exam.';
    view.innerHTML = `
      <header class="whead">
        <div><p class="crumb mono">Topic ${w.number}${w.needs ? `, in the ${esc(w.needs.name.toLowerCase())}` : ''}: ${esc(w.ccnaModules)}</p>
        <h2>${esc(w.title)}</h2><p class="sum">${esc(w.summary)}</p></div>
        ${w.status === 'soon' ? '' : tierChip(w.tier, w.rating)}
      </header>
      ${w.status === 'soon' ? '<p class="empty">Lessons for this topic haven’t been written yet. Lecturers add them in the question bank.</p>'
        : w.status === 'locked' ? `<p class="empty"><i class="ti ti-lock" aria-hidden="true"></i> Master the earlier topics to open this one.${w.needs ? ` You’ll also need to lease the ${esc(w.needs.name.toLowerCase())}.` : ''}</p>`
        : w.status === 'area' ? `<div class="area-gate"><i class="ti ti-building" aria-hidden="true"></i><div><strong>Taught in the ${esc(w.needs.name.toLowerCase())}</strong>
          <small>Lease it from the place menu in the top bar to open these lessons: level ${w.needs.unlockLevel} and ${fmt(w.needs.leaseCost)} credits.${store.state.user.level < w.needs.unlockLevel ? ` You’re level ${store.state.user.level}, so keep learning for XP.` : ''}</small></div></div>` : ''}
      ${w.levels.length ? `<ol class="levels">${levels}</ol>` : ''}
      ${w.levels.length ? `<div class="exam${ex.ready && !locked ? ' ready' : ''}">
        <i class="ti ti-certificate" aria-hidden="true"></i>
        <div><strong>Topic ${w.number} exam</strong><small>${examNote}</small></div>
        <button class="btn primary" data-exam="${w.id}" ${ex.ready && !locked ? '' : 'disabled'}>${w.status === 'mastered' ? 'Retake' : 'Take exam'}</button>
      </div>` : ''}`;
  }

  view.addEventListener('click', async (e) => {
    const t = e.target.closest('button');
    if (!t || t.disabled) return;
    try {
      if (t.dataset.lesson) await showLesson(Number(t.dataset.lesson));
      else if (t.dataset.start) startRun(api.learn.startLevel(Number(t.dataset.start)));
      else if (t.dataset.exam) startRun(api.learn.startExam(Number(t.dataset.exam)));
      else if (t.dataset.auditStart) await startRun(api.learn.startAudit());
      else if (t.dataset.mockWorld) { const id = Number(t.dataset.mockWorld); mockPick.worlds.has(id) ? mockPick.worlds.delete(id) : mockPick.worlds.add(id); showMock(); view.querySelector(`[data-mock-world="${id}"]`)?.focus(); }
      else if (t.dataset.mockLen) { mockPick.length = Number(t.dataset.mockLen); showMock(); view.querySelector(`[data-mock-len="${mockPick.length}"]`)?.focus(); }
      else if (t.dataset.mockStart) await startRun(api.learn.startMock([...mockPick.worlds], mockPick.length));
      else if (t.dataset.back) (worldId === 'audit' ? showAudit() : worldId === 'mock' ? showMock() : showWorld(worldId));
      else if (t.dataset.next) showQuestion();
      else if (t.dataset.check) submit();
      else if (t.dataset.up || t.dataset.down) moveItem(t);
      else if (t.dataset.opt !== undefined) pickOption(t);
    } catch (err) {
      flash(err.message);
    }
  });

  async function showLesson(levelId) {
    const { lesson } = await api.learn.lesson(levelId);
    view.innerHTML = `
      <header class="whead"><div><p class="crumb mono">Topic ${lesson.world.number} · Lesson</p><h2>${esc(lesson.title)}</h2></div></header>
      <article class="lesson">${renderLesson(lesson.lesson)}</article>
      <div class="actions">
        <button class="btn" data-back="1">Back</button>
        <button class="btn primary" data-start="${lesson.id}">Start challenge, ${lesson.questions} questions</button>
      </div>`;
    view.scrollTop = 0;
  }

  /* ---------- challenge run ---------- */

  async function startRun(promise) {
    run = await promise;
    renderList();
    showQuestion();
  }

  function header() {
    const r = run.run;
    const pips = Array.from({ length: r.total }, (_, i) => `<span class="pip${i < r.index ? ' on' : i === r.index ? ' now' : ''}"></span>`).join('');
    return `<header class="qhead">
      <span class="qtitle">${esc(r.title)}</span>
      <span class="pips" aria-label="Question ${r.index + 1} of ${r.total}">${pips}</span>
      ${r.mode === 'mock' ? `<span class="qcount mono">${r.index + 1} of ${r.total}</span>` : tierChip(r.tier, r.rating)}
      <span class="clock mono" id="clock" aria-live="off"></span>
    </header>`;
  }

  function showQuestion() {
    const q = run.question;
    let body = '';
    switch (q.type) {
      case 'mcq':
        body = `<div class="opts" role="radiogroup">${q.options.map((o, i) =>
          `<button class="opt" role="radio" aria-checked="false" data-opt="${i}"><kbd>${i + 1}</kbd>${esc(o)}</button>`).join('')}</div>`;
        break;
      case 'matching':
        body = `<div class="match">${q.left.map((l, i) => `
          <label><span>${esc(l)}</span><select data-match="${i}"><option value="">Choose…</option>
          ${q.right.map((r, j) => `<option value="${j}">${esc(r)}</option>`).join('')}</select></label>`).join('')}</div>`;
        break;
      case 'ordering':
        body = `<ol class="order" id="order">${q.items.map((it, i) => `
          <li data-idx="${i}"><span>${esc(it)}</span>
          <button class="mini" data-up="1" aria-label="Move up"><i class="ti ti-chevron-up"></i></button>
          <button class="mini" data-down="1" aria-label="Move down"><i class="ti ti-chevron-down"></i></button></li>`).join('')}</ol>`;
        break;
      case 'fill':
        body = `<p class="fill">${q.parts.map((p, i) => rich(p) + (i < q.parts.length - 1 ? `<input class="blank" data-blank="${i}" autocomplete="off" spellcheck="false" aria-label="Blank ${i + 1}">` : '')).join('')}</p>`;
        break;
      default: // blitz, subnet
        body = `<input class="big mono" id="free" autocomplete="off" spellcheck="false" inputmode="${q.inputHint === 'Number' ? 'numeric' : 'text'}" placeholder="${esc(q.inputHint)}" aria-label="Your answer">`;
    }
    view.innerHTML = `${header()}
      <section class="q">
        <p class="qmeta">${TYPE_LABEL[q.type]} · ${esc(q.topic)} · <span class="tier-txt">${q.tier}</span></p>
        <h3 class="${q.type === 'fill' ? 'sr-only' : ''}">${rich(q.prompt)}</h3>
        ${body}
        <p class="qerr" id="qerr" role="alert"></p>
      </section>
      <div class="actions"><button class="btn primary" data-check="1">${run.run.mode === 'mock' ? (run.run.index + 1 === run.run.total ? 'Finish exam' : 'Next question') : 'Check answer'} <kbd>Enter</kbd></button></div>`;
    (view.querySelector('#free, .blank, .opt, select') || view.querySelector('[data-check]')).focus();
    startTimer(q.seconds);
  }

  function pickOption(btn) {
    view.querySelectorAll('.opt').forEach((o) => o.setAttribute('aria-checked', String(o === btn)));
    clearErr();
  }

  function moveItem(btn) {
    const li = btn.closest('li');
    if (btn.dataset.up && li.previousElementSibling) li.parentNode.insertBefore(li, li.previousElementSibling);
    if (btn.dataset.down && li.nextElementSibling) li.parentNode.insertBefore(li.nextElementSibling, li);
    btn.focus();
  }

  /** Reads the answer from the form, or returns an error message. */
  function collect() {
    const q = run.question;
    switch (q.type) {
      case 'mcq': {
        const sel = view.querySelector('.opt[aria-checked="true"]');
        return sel ? { answer: Number(sel.dataset.opt) } : { error: 'Pick an answer first.' };
      }
      case 'matching': {
        const vals = [...view.querySelectorAll('[data-match]')].map((s) => s.value);
        return vals.includes('') ? { error: 'Match every item first.' } : { answer: vals.map(Number) };
      }
      case 'ordering':
        return { answer: [...view.querySelectorAll('#order li')].map((li) => Number(li.dataset.idx)) };
      case 'fill': {
        const vals = [...view.querySelectorAll('.blank')].map((i) => i.value);
        return vals.some((v) => !v.trim()) ? { error: 'Fill in every blank first.' } : { answer: vals };
      }
      default: {
        const v = $('free').value.trim();
        return v ? { answer: v } : { error: 'Type an answer first.' };
      }
    }
  }

  let sending = false;
  async function submit(force = false) {
    if (sending || !run) return;
    let { answer, error } = collect();
    if (error && !force) { showErr(error); return; }
    if (error) answer = null; // time ran out with nothing entered
    sending = true;
    stopTimer();
    try {
      const res = await api.learn.answer(answer);
      store.applyServerState(res.state);
      if (audit.due !== res.state.user.auditDue) { audit.due = res.state.user.auditDue; renderList(); }
      const mockRun = run.run.mode === 'mock'; // no feedback mid-exam: answers come at the end
      if (res.summary) { const s = res.summary; run = null; renderList(); return mockRun ? showSummary(s) : showFeedback(res.result, () => showSummary(s)); }
      run = { run: res.run, question: res.question };
      if (mockRun) showQuestion(); else showFeedback(res.result, null);
    } catch (err) {
      showErr(err.message);
    } finally {
      sending = false;
    }
  }

  let afterFeedback = null;
  function showFeedback(r, then) {
    afterFeedback = then;
    if (!r) { then?.(); return; } // exam timed out before this answer
    const delta = r.ratingAfter - r.ratingBefore;
    const gains = [r.credits ? `+${r.credits} credits` : '', r.xp ? `+${r.xp} XP` : ''].filter(Boolean).join(', ');
    const fb = document.createElement('section');
    fb.className = `fb ${r.correct ? 'right' : 'wrong'}`;
    fb.setAttribute('role', 'status');
    fb.innerHTML = `
      <p class="verdict"><i class="ti ${r.correct ? 'ti-circle-check' : 'ti-circle-x'}" aria-hidden="true"></i>${r.correct ? 'Correct' : r.late ? 'Out of time' : 'Not quite'}</p>
      ${r.correct ? '' : `<p class="ans">Answer: <b>${esc(r.answerText)}</b></p>`}
      <p class="why">${rich(r.explanation)}</p>
      <p class="delta mono">Rating ${r.ratingBefore} → ${r.ratingAfter} (${delta >= 0 ? '+' : ''}${delta})${gains ? ` · <span class="gain">${gains}</span>` : ''}${AUDIT_NOTE(r.audit)}</p>`;
    view.querySelector('.actions').replaceWith(fb);
    const next = document.createElement('div');
    next.className = 'actions';
    next.innerHTML = `<button class="btn primary" data-next="1">${then ? 'See results' : 'Next question'} <kbd>Enter</kbd></button>`;
    fb.after(next);
    view.querySelectorAll('.q input, .q select, .q button').forEach((el) => { el.disabled = true; });
    const nb = next.querySelector('button');
    if (then) nb.removeAttribute('data-next'), nb.addEventListener('click', () => then());
    nb.focus();
  }

  function showSummary(s) {
    if (s.mode === 'mock') return showMockSummary(s);
    const delta = s.ratingAfter - s.ratingBefore;
    if (s.mode === 'audit') {
      const back = s.total - s.correct;
      view.innerHTML = `
        <section class="summary ${s.correct === s.total ? 'pass' : 'fail'}">
          <p class="crumb mono">Daily Security Audit</p>
          <p class="score mono">${s.correct}/${s.total}</p>
          <h2>Review done</h2>
          <ul class="audit-out">
            ${s.cleared ? `<li><i class="ti ti-circle-check ok-txt" aria-hidden="true"></i>${s.cleared} cleared for good</li>` : ''}
            ${s.advanced ? `<li><i class="ti ti-calendar-time" aria-hidden="true"></i>${s.advanced} moved to a later review</li>` : ''}
            ${back ? `<li><i class="ti ti-rotate" aria-hidden="true"></i>${back} back tomorrow</li>` : ''}
          </ul>
          <p class="gain mono">+${s.xp} XP</p>
        </section>
        <div class="actions"><button class="btn primary" data-back="1">Back to the audit</button></div>`;
      view.querySelector('[data-back]').focus();
      loadRoadmap().then(renderList);
      return;
    }
    view.innerHTML = `
      <section class="summary ${s.passed ? 'pass' : 'fail'}">
        <p class="crumb mono">${s.mode === 'exam' ? 'Exam result' : 'Level result'}${s.timedOut ? ' · time ran out' : ''}</p>
        <p class="score mono">${s.pct}%</p>
        <h2>${s.mastered ? `Topic mastered` : s.passed ? (s.mode === 'exam' ? 'Exam passed' : 'Level complete') : `Not yet: ${s.passPct}% needed`}</h2>
        <p>${s.correct} of ${s.total} correct. Rating ${s.ratingBefore} → ${s.ratingAfter} (${delta >= 0 ? '+' : ''}${delta}), now ${tierChip(s.tier, s.ratingAfter)}</p>
        <p class="gain mono">+${s.xp} XP</p>
        ${s.unlocked.length ? `<p class="unlock"><i class="ti ti-lock-open" aria-hidden="true"></i>New in the shop: ${s.unlocked.map(esc).join(', ')}</p>` : ''}
        ${s.mode === 'exam' && s.topics.length ? `<table class="topics"><caption>By subtopic</caption><tbody>${s.topics.map((t) =>
          `<tr><th scope="row">${esc(t.topic)}</th><td class="mono">${t.correct}/${t.total}</td></tr>`).join('')}</tbody></table>` : ''}
      </section>
      <div class="actions"><button class="btn primary" data-back="1">Back to Topic</button></div>`;
    view.querySelector('[data-back]').focus();
    loadRoadmap().then(renderList);
  }

  /** One row per topic or world: a bar for the score, a mark at the exam-ready line, the number in text. */
  function scoreRows(rows, name) {
    return rows.map((r) => {
      const pct = Math.round((r.correct / r.total) * 100);
      const weak = pct < mock.readyPct;
      return `<li title="${esc(name(r))}: ${r.correct} of ${r.total} right (${pct}%)">
        <span class="tname">${esc(name(r))}</span>
        <span class="meter" aria-hidden="true"><span class="fill" style="width:${Math.max(pct, 2)}%"></span><span class="line" style="left:${mock.readyPct}%"></span></span>
        <span class="tval mono">${r.correct}/${r.total}</span>
        <span class="tflag">${weak ? '<i class="ti ti-alert-triangle" aria-hidden="true"></i>Work on' : '<i class="ti ti-check" aria-hidden="true"></i>Ready'}</span></li>`;
    }).join('');
  }

  function showMockSummary(s) {
    const ready = s.pct >= s.passPct;
    const unanswered = s.unanswered ? ` ${s.unanswered} left unanswered.` : '';
    view.innerHTML = `<section class="summary mock-sum ${ready ? 'pass' : 'fail'}">
        <p class="crumb mono">${esc(s.title)}${s.timedOut ? ', time ran out' : ''}</p>
        <p class="score mono">${s.pct}%</p>
        <h2>${ready ? 'Exam-ready' : `Not exam-ready yet: ${s.passPct}% needed`}</h2>
        <p>${s.correct} of ${s.total} correct in ${clock(s.seconds ?? 0)}.${unanswered}</p>
        <p class="gain mono">+${s.xp} XP</p>
      </section>
      <h3 class="sub">By subtopic, weakest first</h3>
      <ul class="scores" aria-label="Score by topic">${scoreRows(s.topics, (t) => t.topic)}</ul>
      ${s.byWorld.length > 1 ? `<h3 class="sub">By topic</h3><ul class="scores" aria-label="Score by topic">${scoreRows(s.byWorld, (w) => `Topic ${w.world}`)}</ul>` : ''}
      <p class="legend-note"><span class="line-key" aria-hidden="true"></span>${s.passPct}% exam-ready line</p>
      ${s.missed.length ? `<h3 class="sub">Review your mistakes</h3><ol class="missed">${s.missed.map((m) => `<li>
        <p class="mq">${rich(m.prompt)}</p>
        <p class="ma">${m.late ? 'Out of time. ' : ''}Answer: <b>${esc(m.answer)}</b> <span class="mono">T${m.world}, ${esc(m.topic)}</span></p>
        ${m.why ? `<p class="why">${rich(m.why)}</p>` : ''}</li>`).join('')}</ol>` : ''}
      <p class="note">Wrong answers also go to your Daily Security Audit.</p>
      <div class="actions"><button class="btn primary" data-back="1">Back to mock exams</button></div>`;
    view.querySelector('[data-back]').focus({ preventScroll: true });
    view.scrollTop = 0;
    loadRoadmap().then(renderList);
  }

  /* ---------- keyboard + timers ---------- */

  view.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.matches('.opt') && !e.target.disabled) {
      e.preventDefault();
      pickOption(e.target);
      submit();
      return;
    }
    if (e.key === 'Enter' && !e.target.matches('button')) {
      e.preventDefault();
      const next = view.querySelector('[data-next], .actions button');
      if (view.querySelector('.fb')) next?.click(); else if (run) submit();
    }
    if (run && run.question.type === 'mcq' && /^[1-9]$/.test(e.key) && !e.target.matches('input')) {
      const opt = view.querySelector(`.opt[data-opt="${Number(e.key) - 1}"]`);
      if (opt && !opt.disabled) { pickOption(opt); opt.focus(); }
    }
  });

  function startTimer(qSeconds) {
    stopTimer();
    const examLeft = run.run.secondsLeft;
    const t0 = Date.now();
    const tick = () => {
      const el = $('clock');
      if (!el) return;
      const used = (Date.now() - t0) / 1000;
      const left = qSeconds ?? examLeft;
      if (left == null) { el.textContent = ''; return; }
      const rem = Math.max(0, Math.ceil(left - used));
      el.textContent = `${Math.floor(rem / 60)}:${String(rem % 60).padStart(2, '0')}`;
      el.classList.toggle('low', rem <= (qSeconds ? 5 : 30));
      if (rem === 0) { stopTimer(); submit(true); }
    };
    tick();
    timer = setInterval(tick, 250);
  }
  function stopTimer() { if (timer) clearInterval(timer); timer = null; }

  function showErr(msg) { const el = $('qerr'); if (el) el.textContent = msg; else flash(msg); }
  function clearErr() { const el = $('qerr'); if (el) el.textContent = ''; }
  function flash(msg) { store.log('error', msg); }

  view.addEventListener('input', clearErr);
  view.addEventListener('change', clearErr);

  return { open, close, isOpen: () => !panel.hidden };
}
