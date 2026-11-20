// How money is written everywhere in the game, so the wallet, the coin pops,
// the shop and the guild screen all agree. Mirrors NetForge\Money::short() in PHP.

const UNITS = [[1e12, 'T'], [1e9, 'B'], [1e6, 'M'], [1e3, 'k']];

/**
 * Credits, short: 950, 4k, 12.5k, 3.14M, 1.4B (k thousands, M millions, B billions).
 * figures: significant figures to keep (3 by default; the wallet shows 4 so it visibly counts up).
 */
export function fmt(n, figures = 3) {
  const raw = Number(n) || 0;
  const sign = raw < 0 ? '-' : '';
  const v = Math.abs(raw);
  for (const [unit, suffix] of UNITS) {
    if (v >= unit) {
      const x = v / unit;
      const digits = Math.max(0, figures - (x >= 100 ? 3 : x >= 10 ? 2 : 1));
      const f = 10 ** digits;
      let text = (Math.floor(x * f) / f).toFixed(digits); // round down: never show more than they have
      if (text.includes('.')) text = text.replace(/\.?0+$/, '');
      return `${sign}${text}${suffix}`;
    }
  }
  return `${sign}${Math.floor(v)}`;
}

/** The full number with separators, for tooltips: 1,284,530. */
export const full = (n) => Math.floor(Number(n) || 0).toLocaleString('en-US');

/** Kept for older callers: the same as fmt(). */
export const compact = (n) => fmt(n);

/** Credits per second: "35/s", "22.5/s", "750k/s", "1.25M/s". */
export function rate(perSec) {
  const v = Number(perSec) || 0;
  if (v >= 1000) return `${fmt(v)}/s`;
  return `${v >= 100 ? Math.round(v) : Math.round(v * 10) / 10}/s`;
}
