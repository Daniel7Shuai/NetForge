// Hover labels for every control: a short pause over (or keyboard focus on) anything with
// data-tip, a title, or an icon-only button shows what it does, styled like the rest of the game.
// Titles are moved into data-tip on first hover so the browser's own grey tooltip doesn't double up.

const DELAY = 350;  // ms of hovering before a tip appears
const QUICK = 600;  // after one tip, the next shows straight away for this long (scanning a toolbar)

export function mountTooltips(root = document) {
  const tip = document.createElement('div');
  tip.className = 'tip';
  tip.id = 'tip';
  tip.setAttribute('role', 'tooltip');
  tip.hidden = true;
  document.body.append(tip);

  let target = null, timer = 0, lastHidden = 0;

  /** The element a tip belongs to, or null. */
  function owner(el) {
    const t = el instanceof Element ? el.closest('[data-tip], [title], button[aria-label], a[aria-label]') : null;
    if (!t || t.closest('.tip')) return null;
    if (t.hasAttribute('title')) {
      const title = t.getAttribute('title');
      t.removeAttribute('title');
      if (title) {
        t.dataset.tip = title;
        // keep it available to screen readers if the control has no name of its own
        if (!t.getAttribute('aria-label') && !t.textContent.trim()) t.setAttribute('aria-label', title);
      }
    }
    if (t.dataset.tip) return t;
    // icon-only buttons (at most a shortcut key like "V" for text): use their accessible name
    const visible = t.textContent.replace(/\s+/g, '');
    return t.matches('button[aria-label], a[aria-label]') && visible.length <= 3 ? t : null;
  }
  const textOf = (t) => t.dataset.tip || t.getAttribute('aria-label') || '';

  function show(t) {
    const text = textOf(t);
    if (!text) return;
    target = t;
    tip.textContent = text;
    tip.hidden = false;
    t.setAttribute('aria-describedby', 'tip');
    place(t);
  }
  function hide() {
    clearTimeout(timer);
    if (!tip.hidden) lastHidden = performance.now();
    tip.hidden = true;
    target?.removeAttribute('aria-describedby');
    target = null;
  }
  function place(t) {
    const r = t.getBoundingClientRect();
    const w = tip.offsetWidth, h = tip.offsetHeight, gap = 8;
    // controls in a vertical toolbar get their tip to the side, so it doesn't cover the next tool
    if (t.closest('.rail') && r.right + gap + w < innerWidth) {
      tip.style.left = `${r.right + gap}px`;
      tip.style.top = `${Math.max(4, Math.min(r.top + r.height / 2 - h / 2, innerHeight - h - 4))}px`;
      return;
    }
    let top = r.bottom + gap;
    if (top + h > innerHeight - 4) top = r.top - h - gap; // no room below: go above
    let left = r.left + r.width / 2 - w / 2;
    left = Math.max(6, Math.min(left, innerWidth - w - 6));
    tip.style.top = `${Math.max(4, top)}px`;
    tip.style.left = `${left}px`;
  }
  function soon(t) {
    clearTimeout(timer);
    const wait = performance.now() - lastHidden < QUICK ? 0 : DELAY;
    timer = setTimeout(() => show(t), wait);
  }

  root.addEventListener('pointerover', (e) => {
    if (e.pointerType === 'touch') return;
    const t = owner(e.target);
    if (t === target) return;
    hide();
    if (t) soon(t);
  });
  root.addEventListener('pointerout', (e) => {
    if (target && !target.contains(e.relatedTarget)) hide();
    else if (!target) clearTimeout(timer);
  });
  root.addEventListener('focusin', (e) => {
    if (!(e.target instanceof Element) || !e.target.matches(':focus-visible')) return; // keyboard only
    const t = owner(e.target);
    if (t) { hide(); show(t); }
  });
  root.addEventListener('focusout', hide);
  root.addEventListener('pointerdown', hide, true);
  addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
  addEventListener('scroll', hide, true);
  addEventListener('resize', hide);

  // if a control's explanation changes while its tip is open (a countdown, a lock), keep up
  new MutationObserver(() => {
    if (target && !target.isConnected) hide();
    else if (target && textOf(target) !== tip.textContent) { tip.textContent = textOf(target); place(target); }
  }).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['data-tip', 'title', 'aria-label'], childList: true });
}
