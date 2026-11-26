// The characters don't stop at the tutorial: they turn up along the way. The first time a
// student picks up each kind of cable, meets each kind of port, unlocks gear, can lease a bigger
// place or walks into one, someone explains it. Beyond that, Ada only speaks up when something on
// the student's to-do list (a starred shop item) is ready to buy. Each tip shows once (the server
// remembers which). The lines live in data/tutorial.json, "moments".
//
// Also the one place conversations queue up: store.talk() plays lines when nothing else is
// on screen, so a tip never lands on top of an attack, a lesson or another conversation.
import { api } from './api.js';
import { loadStory } from './story.js';
import { playDialogue } from './dialogue.js';
import { fmt } from './money.js';
import { LOC_CODE } from './game/layout.js';

const GAP = 12000;        // at least this long between two tips

export async function mountMoments(store) {
  const story = await loadStory();
  const script = story.script.moments || {};
  const seen = new Set(story.tutorial.seen || []);
  let queue = Promise.resolve(), talking = false, lastTip = 0;
  const player = () => store.state.user?.name || store.state.user?.username || 'you';
  const blocked = () => {
    const s = store.state;
    return s.learnOpen || s.socialOpen || s.staffOpen || s.termOpen || s.attackOpen || s.phoneOpen || s.cutsceneOpen || document.querySelector('.dlg') || document.hidden;
  };
  const until = (ok) => new Promise((resolve) => {
    const t = setInterval(() => { if (ok()) { clearInterval(t); resolve(); } }, 400);
  });

  /** The lines for a moment, with {item}, {price}... filled in. */
  const linesFor = (key, vars = {}) => (script[key] || []).map(([who, text]) =>
    [who, text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))]);

  /**
   * Plays a conversation (a moment key or [speaker, text] lines) once nothing else is on
   * screen. Resolves with playDialogue's result.
   */
  store.talk = (what, vars = {}, opts = {}) => {
    const run = queue.then(async () => {
      await until(() => !blocked());
      talking = true;
      try {
        const lines = Array.isArray(what) ? what : linesFor(what, vars);
        return lines.length ? await playDialogue(story, lines, { player: player(), skipLabel: 'Close', ...opts }) : 'done';
      } finally {
        talking = false;
        lastTip = Date.now();
      }
    });
    queue = run.catch(() => {});
    return run;
  };
  store.seen = (flag) => seen.has(flag);
  store.markSeen = markSeen;

  function markSeen(flag) {
    if (seen.has(flag)) return;
    seen.add(flag);
    api.seen(flag).catch(() => {});
  }

  /** Shows a moment once: marks it seen first, so a refresh mid-conversation doesn't repeat it. */
  function tip(flag, key = flag, vars = {}, after = null) {
    markSeen(flag);
    store.talk(key, vars).then(() => after?.());
    return true;
  }

  function check() {
    const s = store.state;
    if (!store.tutorialDone || store.rackGuideActive || !s.user || talking || blocked() || store.walking || s.busy) return;
    if (Date.now() - lastTip < GAP) return;

    // walking into a new place
    const here = `arrive:${LOC_CODE}`;
    if (script[here] && !seen.has(here)) return tip(here);

    // the first time each cable comes out
    if (s.tool === 'cable' && store.cableCount(s.cable) > 0 && script[`cable:${s.cable}`] && !seen.has(`cable:${s.cable}`)) {
      return tip(`cable:${s.cable}`);
    }

    // the first time each kind of port shows up in a device's port list
    if (s.picker && s.picker.mode !== 'remove') {
      const dev = s.devices.find((d) => d.id === s.picker.deviceId);
      const kinds = [];
      for (const p of dev?.ports || []) {
        if (p.name === 'WAN') kinds.push('wan');
        kinds.push(p.kind === 'copper' ? p.role : p.kind);
      }
      const k = ['wan', 'mdi', 'mdix', 'fiber', 'console'].find((x) => kinds.includes(x) && !seen.has(`port:${x}`));
      if (k) return tip(`port:${k}`);
    }
    if (store.placingItem() || s.cableDraft || s.moving) return; // don't interrupt the hands-on part

    const live = store.liveCredits ? store.liveCredits() : s.user.credits;

    // a bigger place is within reach
    const lease = (s.locations || []).find((l) => !l.leased && s.user.level >= l.unlockLevel && live >= l.leaseCost);
    if (lease && !seen.has(`lease:${lease.code}`)) return tip(`lease:${lease.code}`, 'lease', { place: lease.name.toLowerCase() });

    // a new level unlocked gear
    const fresh = s.shop.filter((r) => r.unlockLevel === s.user.level && !r.locked && r.unlockLevel > 1);
    if (fresh.length && !seen.has(`level:${s.user.level}`)) {
      const names = [...new Set(fresh.map((r) => r.name.replace(/ \((budget|pro)\)$/, '')))];
      return tip(`level:${s.user.level}`, 'unlock', { level: s.user.level, list: names.join(', ') });
    }

    // GPUs and furniture, explained the first time they're for sale
    const open = (g) => s.shop.some((r) => r.group === g && !r.locked);
    if (open('gpu') && !seen.has('gpu')) return tip('gpu', 'gpu', {}, () => showShop('gpu'));
    if (open('furniture') && !seen.has('furniture')) return tip('furniture');

    // desktops that need staff, and the Work Laptop's terminal, explained the first time
    if (s.staff?.canHire && s.devices.some((d) => d.item_code === 'pc' && !d.worker) && !seen.has('staff')) return tip('staff');
    const ownLaptop = s.inventory.some((i) => i.code === 'work_laptop' && i.quantity > 0) || s.devices.some((d) => d.item_code === 'work_laptop');
    if (ownLaptop && !seen.has('worklaptop')) return tip('worklaptop');

    // Wi-Fi devices and lightweight APs, explained the first time one is bought
    const radioOf = (code) => s.shop.find((r) => r.code === code)?.radio;
    const ownRadio = (radio) => s.inventory.some((i) => i.quantity > 0 && radioOf(i.code) === radio) || s.devices.some((d) => d.radio === radio);
    if (ownRadio('client') && !seen.has('wifi')) return tip('wifi');
    if (ownRadio('lap') && !seen.has('lap')) return tip('lap');

    // power and heat: the first time a place overheats, browns out, or the bill shows up
    const f = s.facility;
    if (f && f.heatFactor < 1 && !seen.has('heat')) return tip('heat', 'heat', { temp: Math.round(f.temp) }, () => showShop('power'));
    if (f && f.powerFactor < 1 && !seen.has('brownout')) return tip('brownout', 'brownout', {}, () => showShop('power'));
    if (f && f.billPerSec >= 1 && !seen.has('bill')) return tip('bill', 'bill', { pct: f.billPct });

    // something on their to-do list is ready to buy: Ada says so, once per item
    const ready = (s.wishlist || []).map((code) => s.shop.find((r) => r.code === code && !r.seller))
      .find((r) => r && !r.locked && r.price <= live && !seen.has(`ready:${r.code}`));
    if (ready) return tip(`ready:${ready.code}`, 'ready', { item: ready.name, price: fmt(ready.price) }, () => showShop(ready.group));
  }

  function showShop(group) {
    store.setTab('shop');
    store.set({ shopGroup: group || 'all' });
  }

  store.subscribe(() => setTimeout(check, 0));
  setInterval(check, 2000);
}
