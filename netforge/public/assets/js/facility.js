// The temperature meter in the top bar, and the power panel it opens: how hot this place is,
// its power load against its supply, the cooling, the electricity bill and the upgrades here.
// When it's too hot the meter sweats; when it's far too hot it shakes as well.
import { rate, kw } from './money.js';
import { esc } from './util.js';

/** cool, warm, hot (sweating, income slowing) or critical (sweating and shaking). */
export function band(f) {
  if (f.temp > f.hot + 6) return 'critical';
  if (f.temp > f.hot) return 'hot';
  if (f.temp > f.hot - 3) return 'warm';
  return 'cool';
}

export function mountFacility(store) {
  const right = document.querySelector('.top-right');
  const btn = document.createElement('button');
  btn.className = 'thermo';
  btn.id = 'thermo';
  btn.type = 'button';
  btn.setAttribute('aria-expanded', 'false');
  btn.setAttribute('aria-controls', 'facility');
  right.prepend(btn);

  const panel = document.createElement('div');
  panel.className = 'facility';
  panel.id = 'facility';
  panel.hidden = true;
  panel.setAttribute('role', 'dialog');
  panel.setAttribute('aria-label', 'Power and cooling');
  right.appendChild(panel);

  let drawn = null; // the facility numbers (and panel state) last drawn: most store changes are about something else
  const close = () => { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.hidden = !panel.hidden;
    btn.setAttribute('aria-expanded', String(!panel.hidden));
    render(store.state);
  });
  document.addEventListener('click', (e) => { if (!panel.contains(e.target) && e.target !== btn) close(); });
  addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  panel.addEventListener('click', (e) => {
    const go = e.target.closest('[data-shop-power]');
    if (go) { close(); store.setTab('shop'); store.set({ shopGroup: 'power' }); }
  });

  store.subscribe(render);
  render(store.state);

  function render(s) {
    const f = s.facility;
    if (!f) { btn.hidden = true; return; }
    if (drawn && drawn.f === f && drawn.open === !panel.hidden) return;
    drawn = { f, open: !panel.hidden };
    btn.hidden = false;
    const b = band(f);
    // the fill: 15°C empty, 45°C full
    const pct = Math.max(6, Math.min(100, ((f.temp - 15) / 30) * 100));
    btn.className = `thermo ${b}`;
    btn.setAttribute('aria-label', `Temperature ${f.temp}°C. Power and cooling.`);
    btn.dataset.tip = b === 'cool' ? `${f.temp}°C here. Cool and running at full speed. Click for power and cooling.`
      : b === 'warm' ? `${f.temp}°C here. Getting warm: above ${f.hot}°C everything here earns less. Click for power and cooling.`
      : `${f.temp}°C here: too hot! Everything here earns ${Math.round((1 - f.heatFactor) * 100)}% less until it cools down. Click for power and cooling.`;
    btn.innerHTML = `<span class="tube" aria-hidden="true"><i style="height:${pct}%"></i></span><span class="bulb" aria-hidden="true"></span>
      <span class="deg mono">${Math.round(f.temp)}°</span>${f.powerFactor < 1 ? '<i class="ti ti-bolt-off brown" aria-hidden="true"></i>' : ''}
      <span class="drops" aria-hidden="true"><i></i><i></i><i></i></span>`;
    if (!panel.hidden) panel.innerHTML = body(s, f, b);
  }

  function body(s, f, b) {
    const loadPct = Math.min(100, (f.load / Math.max(1, f.supply)) * 100);
    const heatPct = Math.min(100, (f.heat / Math.max(1, f.cooling)) * 100);
    const slow = [];
    if (f.heatFactor < 1) slow.push(`Too hot: ${Math.round((1 - f.heatFactor) * 100)}% less income here. Add cooling, or move gear out.`);
    if (f.powerFactor < 1) slow.push(`Not enough power: the gear needs ${kw(f.load)} but there’s only ${kw(f.supply)}, so it all runs at ${Math.round(f.powerFactor * 100)}%. Add a generator.`);
    const names = (codes) => codes.map((c) => s.shop.find((r) => r.code === c)?.name || c);
    const tip = b === 'cool' && f.powerFactor >= 1
      ? 'All good. More gear means more heat and more power: keep an eye on both as you grow.'
      : 'Power & cooling in the shop has fans, air conditioners, CRAC units and generators.';
    return `<h4><i class="ti ti-temperature" aria-hidden="true"></i>Power and cooling <span>${esc(s.user.locationName)}</span></h4>
      <div class="fac-temp ${b}"><b class="mono">${f.temp}°C</b><span>${b === 'cool' ? 'Cool' : b === 'warm' ? 'Warm' : b === 'hot' ? 'Too hot' : 'Overheating'}</span>
        <small>Room ${f.ambient}°C · slows above ${f.hot}°C</small></div>
      ${slow.map((x) => `<p class="fac-warn"><i class="ti ti-alert-triangle" aria-hidden="true"></i>${esc(x)}</p>`).join('')}
      <div class="fac-row" data-tip="What everything running here draws, against the grid supply plus your generators.">
        <span>Power</span><b class="mono">${kw(f.load)} / ${kw(f.supply)}</b>
        <div class="fac-bar ${f.load > f.supply ? 'over' : loadPct > 85 ? 'near' : ''}"><i style="width:${loadPct}%"></i></div>
        <small>Grid ${kw(f.grid)}${f.generators ? ` + generators ${kw(f.generators)}` : ''}</small></div>
      <div class="fac-row" data-tip="The heat your gear gives off, against the cooling here. Over about 70% it gets warm.">
        <span>Heat</span><b class="mono">${kw(f.heat)} / ${kw(f.cooling)} cooling</b>
        <div class="fac-bar heat ${heatPct > 66 ? 'over' : heatPct > 50 ? 'near' : ''}"><i style="width:${heatPct}%"></i></div>
        <small>${f.heatCut ? `Gear runs ${f.heatCut}% cooler · ` : ''}${f.coolBoost ? `cooling +${f.coolBoost}%` : 'no cooling upgrades yet'}</small></div>
      <div class="fac-row" data-tip="The electricity bill is a share of what this place earns. Efficiency upgrades cut it.">
        <span>Electricity bill</span><b class="mono">${f.billPct}% · −${rate(f.billPerSec || 0)}</b>
        <small>${f.billPct < f.baseBillPct ? `Down from ${f.baseBillPct}%` : `Efficiency upgrades take it down to as little as 2%`}</small></div>
      <div class="fac-ups"><span>Upgrades here</span>${f.upgrades.length ? names(f.upgrades).map((n) => `<em>${esc(n)}</em>`).join('') : '<small>None yet</small>'}</div>
      <p class="fac-tip">${esc(tip)}</p>
      <button class="fac-go" data-shop-power="1"><i class="ti ti-building-store" aria-hidden="true"></i>Shop power & cooling</button>`;
  }
}
