// Little helpers the newer panels share.

export const $ = (id) => document.getElementById(id);

/** Escapes text for HTML (null and undefined become ''). */
export const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
