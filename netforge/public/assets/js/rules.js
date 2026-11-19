// Client-side copy of the cabling rules, used only for instant hints
// (greying out ports). The server (src/Rules.php) has the final say.

/** Which port kinds each cable can plug into. */
export const CABLE_FITS = {
  copper: ['copper'],
  crossover: ['copper'],
  fiber: ['fiber'],
  console: ['console'],
};

/** Returns null if the port can take this cable, else the reason it can't. */
export function portProblem(cableKind, port, usedPorts) {
  if (usedPorts.has(port.name)) return 'in use';
  if (!(CABLE_FITS[cableKind] || []).includes(port.kind)) return `needs ${port.kind}`;
  return null;
}
