// Makes earning visible: the wallet in the middle of the top bar counts up every moment,
// and every device that's earning pops a little "+credits" coin as the money comes in.
// The server stays the bank: these only animate between its updates, and every state
// it sends resets the count to the real balance.
import { fmt, full, rate } from './money.js';
import { devicePixel } from './game/MapScene.js';
import { TILE, COLS, ROWS } from './game/layout.js';

const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
const POP_EVERY = 3000; // ms between coins from one device

export function mountEconomy(store) {
  const amount = document.getElementById('credits');
  const rateEl = document.getElementById('income');
  const wallet = document.getElementById('wallet');
  const layer = document.getElementById('coins');

  // the balance the server last sent, and when we got it
  let base = 0, perSec = 0, at = performance.now(), shown = -1;

  store.subscribe(sync);
  // the game state is usually loaded before this mounts, so draw it straight away
  queueMicrotask(() => sync(store.state));

  function sync(s) {
    if (!s.user) return;
    const serverPerSec = s.progress?.income?.perSec ?? 0;
    if (s.user.credits !== base || serverPerSec !== perSec) {
      const ahead = live() - s.user.credits;
      base = s.user.credits;
      at = performance.now();
      // The count may already be a moment past the server's figure (it only pays whole credits).
      // Carry on from where the wallet is rather than stepping back. Real spending is a bigger
      // drop than a couple of seconds' income, so that still shows at once.
      if (ahead > 0 && serverPerSec > 0 && serverPerSec === perSec && ahead <= perSec * 3) {
        at -= (ahead / perSec) * 1000;
      }
      perSec = serverPerSec;
      draw();
    }
    rateEl.querySelector('span').textContent = `+${rate(perSec)}`;
    rateEl.classList.toggle('idle', perSec <= 0);
    const online = s.progress?.income?.devices || [];
    wallet.dataset.tip = perSec > 0
      ? `Your credits. Your network earns ${rate(perSec)} from ${online.length} device${online.length === 1 ? '' : 's'} online: ${online.join(', ')}. Spend credits in the shop (B).`
      : 'Your credits. Nothing is earning yet: link a device to the ISP box with a cable and it starts earning every second.';
  }

  /** Current balance, counting up since the server's last word. */
  const live = () => Math.floor(base + perSec * ((performance.now() - at) / 1000));
  store.liveCredits = live;

  function draw() {
    const v = live();
    if (v === shown) return;
    shown = v;
    amount.textContent = fmt(v, 4); // four figures, so it visibly counts up
    amount.title = `${full(v)} credits`;
  }
  // a few frames a second is enough to look alive without burning battery
  setInterval(() => { if (!document.hidden) draw(); }, 120);

  /* ---- coins popping out of earning devices ---- */
  const lastPop = new Map(); // device id -> time of its last coin
  setInterval(() => {
    if (document.hidden || store.state.learnOpen || store.state.socialOpen) return;
    const now = performance.now();
    for (const d of store.state.devices || []) {
      if (!(d.earning > 0)) continue;
      // the first time a device is seen, stagger its start so coins don't all pop at once
      if (!lastPop.has(d.id)) lastPop.set(d.id, now - POP_EVERY * ((d.id % 7) / 7));
      if (now - lastPop.get(d.id) < POP_EVERY) continue;
      lastPop.set(d.id, now);
      popCoin(d, d.earning * (POP_EVERY / 1000));
    }
  }, 250);

  function popCoin(d, amountEarned) {
    const px = devicePixel(d);
    const el = document.createElement('div');
    el.className = 'coin-pop' + (reduceMotion ? ' still' : '');
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML = `<i class="ti ti-coin"></i>+${fmt(amountEarned)}`;
    el.style.left = `${((px.x + px.w / 2) / (COLS * TILE)) * 100}%`;
    el.style.top = `${(px.y / (ROWS * TILE)) * 100}%`;
    layer.append(el);
    el.addEventListener('animationend', () => el.remove());
    setTimeout(() => el.remove(), 2500); // in case the animation never runs
  }
}
