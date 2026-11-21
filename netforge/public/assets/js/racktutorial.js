// The rack-building guide: Ada walks the student through building a first server rack, with a
// free starter kit (PDU, patch panel, switch, server, cables). Each step is a few lines, then a
// task card; the step ends when the game state shows it's done, so a reload picks up where they
// were. The finished rack is a real one: it stays in the office or datacentre and earns.
// Ada offers it once, the first time the student is somewhere with racks; the to-do list's
// "Guide me" button starts it any time. The lines live in data/tutorial.json, "rackGuide".
import { api } from './api.js';
import { loadStory } from './story.js';
import { taskCard } from './tutorial.js';

export async function mountRackGuide(store) {
  const story = await loadStory();
  const G = story.script.rackGuide;
  const seen = new Set(story.tutorial.seen || []);
  const mark = (flag) => { if (!seen.has(flag)) { seen.add(flag); api.seen(flag).catch(() => {}); } };
  let active = seen.has('racktut:start') && !seen.has('racktut:done');
  let idx = 0, said = -1, talking = false, card = null, glowing = null;

  const place = () => (store.state.user?.locationName || 'office').toLowerCase();
  const lines = (list) => list.map(([who, text]) => [who, text.replaceAll('{place}', place())]);

  /* ---------- where the student is up to ---------- */
  const contents = (r) => store.rackContents(r);
  const isServer = (d) => d.size >= 2 && d.rate > 0;
  const isSwitch = (d) => d.sprite === 'switch1u';
  const linked = (a, b) => store.state.cables.some((c) => (c.a_device_id === a.id && c.b_device_id === b.id) || (c.a_device_id === b.id && c.b_device_id === a.id));
  const GOALS = {
    open: (s) => !!s.rackView,
    pdu: (s, r) => contents(r).some((d) => d.item_code === 'pdu'),
    patch: (s, r) => contents(r).some((d) => d.item_code === 'patch_panel'),
    switch: (s, r) => contents(r).some(isSwitch),
    server: (s, r) => contents(r).some(isServer),
    rear: (s) => s.rackView?.face === 'rear',
    cabled: (s, r) => contents(r).filter(isServer).some((sv) => contents(r).filter(isSwitch).some((sw) => linked(sv, sw))),
    online: (s, r) => contents(r).some((d) => isServer(d) && d.earning > 0),
  };
  const BUILD = ['pdu', 'patch', 'switch', 'server', 'cabled', 'online'];

  /** The rack being built: the one furthest along, else the one that's open, else an empty one. */
  function target() {
    const s = store.state, racks = s.racks || [];
    if (!racks.length) return null;
    const score = (r) => BUILD.filter((g) => GOALS[g](s, r)).length;
    const best = racks.reduce((a, b) => (score(b) > score(a) ? b : a));
    if (score(best) > 0) return best;
    return (s.rackView && store.rackAt(s.rackView.x, s.rackView.y)) || racks.find((r) => !contents(r).length) || racks[0];
  }

  /** Is step i done? The look-at-it steps (open, rear) also count once the step after them is. */
  function stepDone(i, r) {
    const g = G.steps[i].goal, s = store.state;
    if (GOALS[g](s, r)) return true;
    return (g === 'open' || g === 'rear') && i + 1 < G.steps.length && GOALS[G.steps[i + 1].goal](s, r);
  }

  /* ---------- running it ---------- */
  store.rackGuideActive = active;
  store.startRackGuide = start;
  store.subscribe(() => tick());
  setInterval(tick, 1000);
  tick();

  async function start() {
    if (!(store.state.racks || []).length) { store.log('warn', 'Racks are in the office and the datacentre. Go there to build one.'); return; }
    if (!seen.has('rackkit') && !(await store.rackCall('kit', {}))) return;
    seen.add('rackkit');
    mark('racktut:start');
    active = store.rackGuideActive = true;
    idx = 0; said = -1;
    store.set({}); // redraw the to-do list without its "Guide me" button
    tick();
  }

  function stop() {
    active = store.rackGuideActive = false;
    hideCard();
    store.set({ guideRack: null });
    store.log('info', 'Guide stopped. “Guide me” on your to-do list starts it again.');
  }

  async function finish() {
    active = store.rackGuideActive = false;
    mark('racktut:done');
    hideCard();
    store.set({ guideRack: null });
    talking = true;
    await store.talk(lines(G.done));
    talking = false;
  }

  async function tick() {
    glow();
    const s = store.state;
    if (talking || !s.user || !store.talk) return;
    if (!active) { offer(); return; }
    const r = target();
    if (!r) { hideCard(); if (s.guideRack) store.set({ guideRack: null }); return; } // somewhere without racks: wait
    while (idx < G.steps.length && stepDone(idx, r)) idx++;
    if (idx >= G.steps.length) { finish(); return; }
    // point at the rack on the map while it's closed and the work is inside it (not for the last cable, out on the map)
    const want = !s.rackView && G.steps[idx].goal !== 'online' ? { x: r.x, y: r.y } : null;
    if (JSON.stringify(want) !== JSON.stringify(s.guideRack ?? null)) store.set({ guideRack: want });
    if (said === idx) return;
    // a new step: Ada explains, then the task card stays up until it's done
    said = idx;
    hideCard();
    const step = G.steps[idx];
    talking = true;
    const res = await store.talk(lines(step.say), {}, { skipLabel: 'Stop the guide' });
    talking = false;
    if (res === 'skipped') { stop(); return; }
    if (active && said === idx) showCard(step);
    tick();
  }

  /** Ada offers the guide once, the first time the student is somewhere with racks. */
  async function offer() {
    const s = store.state;
    if (seen.has('racktut:offer') || seen.has('racktut:start') || !store.tutorialDone || !(s.racks || []).length
      || store.placingItem() || s.cableDraft || s.rackView || s.busy) return;
    mark('racktut:offer');
    talking = true;
    const res = await store.talk(lines(G.offer), {}, { skipLabel: 'Maybe later', choices: [{ label: 'Show me', value: 'yes' }, { label: 'Maybe later', value: 'no' }] });
    talking = false;
    if (res === 'yes') start();
    else store.log('info', 'Any time: “Guide me” on your to-do list starts the rack guide.');
  }

  function showCard(step) {
    hideCard();
    store.set({ guideTask: step.task });
    card = taskCard(story, step.say[0]?.[0] || 'teacher', step.task, {
      skipLabel: 'Stop the guide (“Guide me” on the to-do list starts it again)',
      onSkip: stop,
      onAgain: async () => {
        if (talking) return;
        talking = true;
        await store.talk(lines(step.say), {}, { skipLabel: 'Close' });
        talking = false;
      },
    });
    card.classList.add('guide-card'); // bottom right, clear of the ISP box up top
  }
  function hideCard() {
    card?.remove(); card = null;
    if (store.state.guideTask) store.set({ guideTask: null });
  }

  /** Makes the control for the current step glow (the first of its targets that's on screen). */
  function glow() {
    const step = active && card ? G.steps[idx] : null;
    const pick = (list) => { // the first one on screen; among empty U, the one at the end of the rack the step wants
      const shown = list.filter((e) => e.offsetParent !== null);
      if (shown[0]?.dataset.u && step.u) shown.sort((a, b) => (step.u === 'top' ? b.dataset.u - a.dataset.u : a.dataset.u - b.dataset.u));
      return shown[0];
    };
    const el = step?.glow ? step.glow.split(',').map((q) => pick([...document.querySelectorAll(q.trim())])).find(Boolean) : null;
    if (el === glowing) return;
    glowing?.classList.remove('guide-glow');
    el?.classList.add('guide-glow');
    glowing = el;
  }
}
