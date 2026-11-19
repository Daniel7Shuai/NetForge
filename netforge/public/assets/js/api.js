// Thin wrapper over the PHP endpoints in /api. Every call returns parsed JSON
// or throws an Error whose message is safe to show the player.

async function call(path, options = {}) {
  const res = await fetch(`api/${path}`, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.NF.csrf },
    ...options,
  });
  let data;
  try { data = await res.json(); } catch { data = { ok: false, error: 'The server sent an unreadable reply.' }; }
  if (res.status === 401) { location.href = 'login.php'; }
  if (!data.ok) throw new Error(data.error || 'That action failed.');
  return data;
}

export const api = {
  state: () => call('state.php'),
  place: (itemCode, tile) => call('place.php', { method: 'POST', body: JSON.stringify({ item: itemCode, x: tile.x, y: tile.y }) }),
  move: (deviceId, tile) => call('move.php', { method: 'POST', body: JSON.stringify({ device: deviceId, x: tile.x, y: tile.y }) }),
  connect: (cable, a, b) => call('connect.php', { method: 'POST', body: JSON.stringify({ cable, a, b }) }),
  unplug: (cableId) => call('unplug.php', { method: 'POST', body: JSON.stringify({ cable: cableId }) }),
  pickUp: (deviceId) => call('pickup.php', { method: 'POST', body: JSON.stringify({ device: deviceId }) }),
  buy: (shopId) => call('buy.php', { method: 'POST', body: JSON.stringify({ id: shopId }) }),
  learn: {
    roadmap: () => call('learn/roadmap.php'),
    lesson: (levelId) => call(`learn/lesson.php?id=${encodeURIComponent(levelId)}`),
    startLevel: (levelId) => call('learn/start.php', { method: 'POST', body: JSON.stringify({ level: levelId }) }),
    startExam: (worldId) => call('learn/start.php', { method: 'POST', body: JSON.stringify({ exam: worldId }) }),
    startAudit: () => call('learn/start.php', { method: 'POST', body: JSON.stringify({ audit: true }) }),
    answer: (answer) => call('learn/answer.php', { method: 'POST', body: JSON.stringify({ answer }) }),
    quit: () => call('learn/quit.php', { method: 'POST', body: '{}' }),
  },
};
