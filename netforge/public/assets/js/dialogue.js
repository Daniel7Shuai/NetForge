// The dialogue box: a text box along the bottom of the map with the speaking character
// standing on top of it, left or right. Click or Enter for the next line, Skip to jump past
// the whole conversation. Characters with no uploaded art get their built-in pixel portrait.
import { portrait, PORTRAIT_SIZE } from './game/art.js';
import { character, fill } from './story.js';

const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

/** A portrait element for a character: their uploaded art, or the built-in pixel one. */
export function portraitEl(c, scale = 5) {
  if (c.art) {
    const img = new Image();
    img.src = c.art;
    img.alt = '';
    return img;
  }
  const cv = document.createElement('canvas');
  cv.width = PORTRAIT_SIZE * scale; cv.height = PORTRAIT_SIZE * scale;
  const ctx = cv.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  ctx.scale(scale, scale);
  portrait(ctx, c.code);
  return cv;
}

/**
 * Plays a list of [speaker, text] lines. Resolves 'done' after the last line, or 'skipped'.
 * Options: player (name for {player}), skipLabel, choices: [{label, value}] offered on the last
 * line instead of Done (resolves with the chosen value; Esc or Skip still resolve 'skipped'),
 * host: the element to show it in (the map by default).
 */
export function playDialogue(story, lines, { player = 'you', skipLabel = 'Skip', choices = null, host: into = null } = {}) {
  return new Promise((resolve) => {
    const host = into || document.querySelector('.stage'); // a cutscene passes its own stage
    const root = document.createElement('div');
    root.className = 'dlg';
    root.innerHTML = `<div class="dlg-shade"></div><div class="dlg-cast"></div>
      <div class="dlg-box" role="dialog" aria-label="Dialogue">
        <div class="dlg-name"><b></b><small></small></div>
        <p class="dlg-text" aria-live="polite"></p>
        <div class="dlg-actions">
          <button type="button" class="dlg-skip" data-tip="Skip this conversation (Esc)">${esc(skipLabel)}</button>
          <button type="button" class="dlg-next">Next <kbd>Enter</kbd></button>
        </div>
      </div>`;
    host.append(root);

    // everyone who speaks in this conversation stands on the box; the speaker is lit
    const cast = new Map();
    for (const code of new Set(lines.map((l) => l[0]))) {
      const c = character(story, code);
      const fig = document.createElement('figure');
      fig.className = `dlg-portrait ${c.side}`;
      fig.append(portraitEl(c));
      root.querySelector('.dlg-cast').append(fig);
      cast.set(code, fig);
    }
    const box = root.querySelector('.dlg-box');
    // leave room for the portraits standing at each end of the box
    const sides = new Set([...cast.keys()].map((code) => character(story, code).side));
    box.classList.toggle('has-left', sides.has('left'));
    box.classList.toggle('has-right', sides.has('right'));
    const nameEl = root.querySelector('.dlg-name b'), roleEl = root.querySelector('.dlg-name small');
    const textEl = root.querySelector('.dlg-text'), nextBtn = root.querySelector('.dlg-next');

    let i = -1, full = '', shown = 0, timer = 0;
    const typing = () => shown < full.length;
    function show(n) {
      i = n;
      const [code, text] = lines[i];
      const c = character(story, code);
      nameEl.textContent = c.name;
      roleEl.textContent = c.role;
      box.dataset.side = c.side;
      for (const [k, fig] of cast) fig.classList.toggle('on', k === code);
      full = fill(story, text, player);
      shown = reduceMotion ? full.length : 0;
      textEl.textContent = full.slice(0, shown);
      nextBtn.innerHTML = i === lines.length - 1 ? 'Done <kbd>Enter</kbd>' : 'Next <kbd>Enter</kbd>';
      // a question on the last line: its answers replace the Done button
      const last = i === lines.length - 1 && choices?.length;
      nextBtn.hidden = !!last;
      root.querySelector('.dlg-choices')?.remove();
      if (last) {
        const row = document.createElement('div');
        row.className = 'dlg-choices';
        choices.forEach((ch, n) => {
          const b = document.createElement('button');
          b.type = 'button';
          b.className = n === 0 ? 'dlg-choice primary' : 'dlg-choice';
          b.innerHTML = `${esc(ch.label)} <kbd>${n + 1}</kbd>`;
          b.addEventListener('click', (e) => { e.stopPropagation(); close(ch.value); });
          row.append(b);
        });
        root.querySelector('.dlg-actions').prepend(row);
      }
      root.style.setProperty('--dlg-h', `${box.offsetHeight}px`); // phones stand the speaker on top of the box
      clearInterval(timer);
      if (!reduceMotion) {
        timer = setInterval(() => {
          shown += 2;
          textEl.textContent = full.slice(0, shown);
          if (!typing()) clearInterval(timer);
        }, 28);
      }
    }
    function next() {
      // the first press finishes the line being typed; the next moves on
      if (typing()) { shown = full.length; textEl.textContent = full; clearInterval(timer); return; }
      if (i < lines.length - 1) show(i + 1); else if (!choices?.length) close('done');
    }
    function close(result) {
      clearInterval(timer);
      removeEventListener('keydown', onKey, true);
      root.classList.add('out');
      setTimeout(() => root.remove(), reduceMotion ? 0 : 180);
      resolve(result);
    }
    function onKey(e) {
      const pick = choices?.length && i === lines.length - 1 && !typing() ? choices[Number(e.key) - 1] : null;
      if (pick) { e.preventDefault(); e.stopPropagation(); close(pick.value); }
      else if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); e.stopPropagation(); next(); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close('skipped'); }
      else e.stopPropagation(); // the game's shortcuts wait until the conversation ends
    }
    addEventListener('keydown', onKey, true);
    box.addEventListener('click', (e) => { if (!e.target.closest('.dlg-skip')) next(); });
    root.querySelector('.dlg-shade').addEventListener('click', next);
    root.querySelector('.dlg-skip').addEventListener('click', () => close('skipped'));
    show(0);
    nextBtn.focus({ preventScroll: true });
  });
}
