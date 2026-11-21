// The guided tutorial: the network teacher walks new students from installing the laptop to
// cabling the ISP. Each step is a conversation that ends on a task: its last line stays up until
// the game state shows the task is done, then the next step starts by itself (Enter doesn't skip
// it). Progress is saved on the server.
import { api } from './api.js';
import { loadStory, fill } from './story.js';
import { playDialogue } from './dialogue.js';
import { playIntro } from './cutscene.js';

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
  let busy = false;
  const player = () => store.state.user?.name || store.state.user?.username || 'you';
  const blocked = () => store.state.learnOpen || store.state.socialOpen || store.state.attackOpen || store.state.cutsceneOpen || document.querySelector('.dlg');

  // "Replay tutorial" in the menu
  document.getElementById('tutorial-replay')?.addEventListener('click', async () => {
    await api.tutorial('restart');
    location.reload();
  });

  // "Watch the intro" in the menu
  document.getElementById('intro-replay')?.addEventListener('click', () => { if (!store.state.cutsceneOpen) playIntro(store); });

  store.tutorialDone = done; // the characters' other tips wait until the tutorial is over
  if (done) return;
  // a brand-new student: the story starts with the raid on Dad's datacentre
  if (step === 0 && !(story.tutorial.seen || []).includes('intro')) {
    api.seen('intro').catch(() => {});
    busy = true;
    await playIntro(store);
    busy = false;
  }
  store.subscribe(() => tick());
  setInterval(() => tick(), 1000); // also catch up after Learn, an attack or a conversation closes
  tick();

  async function tick() {
    glow();
    if (busy || done || !store.state.user || blocked()) return;
    const s = steps[step];
    if (!s) { finish(); return; }
    // a task they'd already done before the tutorial got here: skip its talk
    if (s.task && goalMet(s.goal, store.state)) { await advance(); return; }
    busy = true;
    s.shown = true; // its control can glow now
    const wait = s.task ? { task: fill(story, s.task, player()), waitUntil: () => goalMet(s.goal, store.state) } : {};
    const result = await playDialogue(story, s.say, { player: player(), skipLabel: 'Skip tutorial', ...wait });
    busy = false;
    if (result === 'skipped') { skipAll(); return; }
    await advance();
  }

  async function advance() {
    step += 1;
    api.tutorial('step', step).catch(() => {});
    if (step >= steps.length) { finish(); return; }
    tick();
  }
  function finish() {
    if (done) return;
    done = true;
    store.tutorialDone = true;
    glow();
    api.tutorial('done').catch(() => {});
  }
  function skipAll() {
    if (!confirm('Skip the tutorial? You can replay it any time from the menu.')) { tick(); return; } // back to the step
    finish();
  }

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
