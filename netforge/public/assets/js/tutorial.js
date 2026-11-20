// The guided tutorial: the network teacher walks new students from installing the laptop to
// cabling the ISP. Each step is a conversation, then a task the student does in the game; the
// next step starts once the game state shows the task is done. Progress is saved on the server.
import { api } from './api.js';
import { loadStory, character, fill } from './story.js';
import { playDialogue, portraitEl } from './dialogue.js';

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));

/** Is a step's goal met in this game state? Goals: placed:<item>, owns:<item>, link:<item>:<item>. */
function goalMet(goal, s) {
  if (!goal) return true;
  const [kind, a, b] = goal.split(':');
  const placed = (code) => s.devices.some((d) => d.item_code === code);
  if (kind === 'placed') return placed(a);
  if (kind === 'owns') return placed(a) || s.inventory.some((i) => i.code === a && i.quantity > 0) || s.cables.some((c) => c.code === a);
  if (kind === 'link') {
    const code = (id) => s.devices.find((d) => d.id === id)?.item_code;
    return s.cables.some((c) => c.status === 'up' && [code(c.a_device_id), code(c.b_device_id)].sort().join() === [a, b].sort().join());
  }
  return false;
}

export async function mountTutorial(store) {
  const story = await loadStory();
  const steps = story.script.steps;
  let { step, done } = story.tutorial;
  let busy = false, card = null;
  const player = () => store.state.user?.name || store.state.user?.username || 'you';
  const blocked = () => store.state.learnOpen || store.state.socialOpen || store.state.attackOpen || document.querySelector('.dlg');

  // "Replay tutorial" in the menu
  document.getElementById('tutorial-replay')?.addEventListener('click', async () => {
    await api.tutorial('restart');
    location.reload();
  });

  store.tutorialDone = done; // the characters' other tips wait until the tutorial is over
  if (done) return;
  store.subscribe(() => tick());
  setInterval(() => tick(), 1000); // also catch up after Learn, an attack or a conversation closes
  tick();

  async function tick() {
    glow();
    if (busy || done || !store.state.user || blocked()) return;
    const s = steps[step];
    if (!s) { finish(); return; }
    if (s.task && s.shown) {
      if (goalMet(s.goal, store.state)) await advance(); // done while the task card was up
      return;
    }
    // a task they'd already done before the tutorial got here: skip its talk
    if (s.task && goalMet(s.goal, store.state)) { await advance(); return; }
    busy = true;
    const result = await playDialogue(story, s.say, { player: player(), skipLabel: 'Skip tutorial' });
    busy = false;
    if (result === 'skipped') { skipAll(); return; }
    if (s.task) { s.shown = true; showCard(s); tick(); } else await advance();
  }

  async function advance() {
    hideCard();
    step += 1;
    api.tutorial('step', step).catch(() => {});
    if (step >= steps.length) { finish(); return; }
    tick();
  }
  function finish() {
    if (done) return;
    done = true;
    store.tutorialDone = true;
    hideCard();
    glow();
    api.tutorial('done').catch(() => {});
  }
  function skipAll() {
    if (!confirm('Skip the tutorial? You can replay it any time from the menu.')) {
      if (steps[step]?.task) { steps[step].shown = true; showCard(steps[step]); }
      return;
    }
    finish();
  }

  /** The small card that keeps the current task in view while the student does it. */
  function showCard(s) {
    hideCard();
    const teacher = character(story, s.say[0]?.[0] || 'teacher');
    card = document.createElement('div');
    card.className = 'tut-task';
    card.innerHTML = `<div class="tut-face"></div>
      <div class="tut-body"><small>${esc(teacher.name)}</small><p>${esc(fill(story, s.task, player()))}</p></div>
      <div class="tut-actions">
        <button type="button" data-again aria-label="Hear the instructions again"><i class="ti ti-message-2" aria-hidden="true"></i></button>
        <button type="button" data-skip aria-label="Skip the tutorial (replay it from the menu)"><i class="ti ti-player-skip-forward" aria-hidden="true"></i></button>
      </div>`;
    card.querySelector('.tut-face').append(portraitEl(teacher, 2));
    card.querySelector('[data-again]').addEventListener('click', async () => {
      if (busy) return;
      busy = true;
      await playDialogue(story, s.say, { player: player(), skipLabel: 'Close' });
      busy = false;
      tick();
    });
    card.querySelector('[data-skip]').addEventListener('click', skipAll);
    document.querySelector('.stage').append(card);
  }
  function hideCard() { card?.remove(); card = null; }

  /** Makes the control for the current task glow, so the student knows where to look. */
  function glow() {
    const s = steps[step];
    // the targets are listed in order of preference: the item itself if it's on screen, else its tab
    const want = !done && s?.shown && s.glow
      ? s.glow.split(',').map((sel) => [...document.querySelectorAll(sel.trim())].find((e) => e.offsetParent !== null)).find(Boolean)
      : null;
    document.querySelectorAll('.tut-glow').forEach((el) => { if (el !== want) el.classList.remove('tut-glow'); });
    want?.classList.add('tut-glow');
  }
}
