// The Learn screen (module 3.0): roadmap of worlds, lessons, challenge runs and exams.
// It only shows what the server sends; marking, Elo and rewards all happen in PHP.
import { api } from './api.js';

const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
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

/** Light markdown used in lessons: paragraphs, "- " lists, **bold**, `code`. Escapes first. */
export function renderLesson(md) {
  const inline = (s) => esc(s).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  return md.trim().split(/\n\s*\n/).map((block) => {
    const lines = block.split('\n');
    if (lines.every((l) => l.trim().startsWith('- '))) {
      return `<ul>${lines.map((l) => `<li>${inline(l.trim().slice(2))}</li>`).join('')}</ul>`;
    }
    return `<p>${inline(lines.join(' '))}</p>`;
  }).join('');
}

const tierChip = (tier, rating) => `<span class="tier ${TIER_CLASS[tier]}" title="Elo rating ${rating}">${tier} <b class="mono">${rating}</b></span>`;

export function mountLearn(store) {
  const panel = $('learn');
  const list = $('learn-worlds');
  const view = $('learn-view');
  let worlds = [];
  let audit = { due: 0, total: 0, stages: {}, upcoming: [], topics: [] };
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
    renderList();
  }

  function renderList() {
    const auditRow = `<button class="world audit-row${worldId === 'audit' ? ' on' : ''}${audit.due ? ' due' : ''}" data-audit="1">
        <i class="ti ti-shield-check wnum" aria-hidden="true"></i>
        <span class="wtitle">Daily Security Audit<small>${audit.due ? `${audit.due} due today` : audit.total ? 'Nothing due today' : 'No mistakes to review'}</small></span>
        ${audit.due ? `<span class="due-badge mono">${audit.due}</span>` : '<i class="ti ti-circle-check" aria-hidden="true"></i>'}</button>`;
    list.innerHTML = auditRow + worlds.map((w) => {
      const icon = { mastered: 'ti-circle-check', open: 'ti-player-play', locked: 'ti-lock', soon: 'ti-clock' }[w.status];
      const meta = w.status === 'soon' ? 'Coming soon' : w.status === 'locked' ? 'Locked' : w.tier;
      return `<button class="world ${w.status}${w.id === worldId ? ' on' : ''}" data-world="${w.id}" ${w.status === 'soon' ? 'aria-disabled="true"' : ''}>
        <span class="wnum mono">W${w.number}</span>
        <span class="wtitle">${esc(w.title)}<small>${meta}</small></span>
        <i class="ti ${icon}" aria-hidden="true"></i></button>`;
    }).join('');
  }
  list.addEventListener('click', (e) => {
    if (run) return;
    if (e.target.closest('[data-audit]')) { showAudit(); return; }
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

  function showWorld(id) {
    stopTimer();
    worldId = id;
    renderList();
    const w = worlds.find((x) => x.id === id);
    const locked = w.status === 'locked' || w.status === 'soon';
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
        <div><p class="crumb mono">World ${w.number} · CCNA ITN modules ${esc(w.ccnaModules)}</p>
        <h2>${esc(w.title)}</h2><p class="sum">${esc(w.summary)}</p></div>
        ${w.status === 'soon' ? '' : tierChip(w.tier, w.rating)}
      </header>
      ${w.status === 'soon' ? '<p class="empty">Lessons for this world haven’t been written yet. Lecturers add them in the question bank.</p>'
        : w.status === 'locked' ? '<p class="empty"><i class="ti ti-lock" aria-hidden="true"></i> Master the earlier worlds to open this one.</p>' : ''}
      ${w.levels.length ? `<ol class="levels">${levels}</ol>` : ''}
      ${w.levels.length ? `<div class="exam${ex.ready && !locked ? ' ready' : ''}">
        <i class="ti ti-certificate" aria-hidden="true"></i>
        <div><strong>World ${w.number} exam</strong><small>${examNote}</small></div>
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
      else if (t.dataset.back) (worldId === 'audit' ? showAudit() : showWorld(worldId));
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
      <header class="whead"><div><p class="crumb mono">World ${lesson.world.number} · Lesson</p><h2>${esc(lesson.title)}</h2></div></header>
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
      ${tierChip(r.tier, r.rating)}
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
        body = `<p class="fill">${q.parts.map((p, i) => esc(p) + (i < q.parts.length - 1 ? `<input class="blank" data-blank="${i}" autocomplete="off" spellcheck="false" aria-label="Blank ${i + 1}">` : '')).join('')}</p>`;
        break;
      default: // blitz, subnet
        body = `<input class="big mono" id="free" autocomplete="off" spellcheck="false" inputmode="${q.inputHint === 'Number' ? 'numeric' : 'text'}" placeholder="${esc(q.inputHint)}" aria-label="Your answer">`;
    }
    view.innerHTML = `${header()}
      <section class="q">
        <p class="qmeta">${TYPE_LABEL[q.type]} · ${esc(q.topic)} · <span class="tier-txt">${q.tier}</span></p>
        <h3 class="${q.type === 'fill' ? 'sr-only' : ''}">${esc(q.prompt)}</h3>
        ${body}
        <p class="qerr" id="qerr" role="alert"></p>
      </section>
      <div class="actions"><button class="btn primary" data-check="1">Check answer <kbd>Enter</kbd></button></div>`;
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
      if (res.summary) { const s = res.summary; run = null; renderList(); return showFeedback(res.result, () => showSummary(s)); }
      run = { run: res.run, question: res.question };
      showFeedback(res.result, null);
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
      <p class="why">${esc(r.explanation)}</p>
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
        <h2>${s.mastered ? `World mastered` : s.passed ? (s.mode === 'exam' ? 'Exam passed' : 'Level complete') : `Not yet: ${s.passPct}% needed`}</h2>
        <p>${s.correct} of ${s.total} correct. Rating ${s.ratingBefore} → ${s.ratingAfter} (${delta >= 0 ? '+' : ''}${delta}), now ${tierChip(s.tier, s.ratingAfter)}</p>
        <p class="gain mono">+${s.xp} XP</p>
        ${s.unlocked.length ? `<p class="unlock"><i class="ti ti-lock-open" aria-hidden="true"></i>New in the shop: ${s.unlocked.map(esc).join(', ')}</p>` : ''}
        ${s.mode === 'exam' && s.topics.length ? `<table class="topics"><caption>By topic</caption><tbody>${s.topics.map((t) =>
          `<tr><th scope="row">${esc(t.topic)}</th><td class="mono">${t.correct}/${t.total}</td></tr>`).join('')}</tbody></table>` : ''}
      </section>
      <div class="actions"><button class="btn primary" data-back="1">Back to World</button></div>`;
    view.querySelector('[data-back]').focus();
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
