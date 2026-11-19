// How money is written everywhere in the game, so the wallet, the coin pops,
// the shop and the guild screen all agree.

/** Whole credits with separators: 1,284,530. */
export const fmt = (n) => Math.floor(Number(n) || 0).toLocaleString('en-US');

/** A short form for tight spots: 950, 12.5K, 3.40M, 1.25B. */
export function compact(n) {
  const v = Math.floor(Number(n) || 0);
  if (v >= 1e12) return `${(v / 1e12).toFixed(2)}T`;
  if (v >= 1e9) return `${(v / 1e9).toFixed(2)}B`;
  if (v >= 1e6) return `${(v / 1e6).toFixed(2)}M`;
  if (v >= 1e4) return `${(v / 1e3).toFixed(1)}K`;
  return fmt(v);
}

/** Credits per second: "35/s", "22.5/s", "750,000/s". */
export function rate(perSec) {
  const v = Number(perSec) || 0;
  const shown = v >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
  return `${shown.toLocaleString('en-US')}/s`;
}
