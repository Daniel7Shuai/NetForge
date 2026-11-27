// The Work Laptop's terminal: a console session on a Cisco router or switch.
// The server runs every command (src/Cli.php); this file shows the scrollback, keeps the
// mode the session is in, remembers the commands typed (up and down arrows) and ticks the checklist.
import { api } from './api.js';
import { $, esc } from './util.js';

export function mountTerminal(store) {
  const panel = $('terminal');
  const out = $('term-out');
  const input = $('term-in');
  const prompt = $('term-prompt');
  const side = $('term-side');
  let session = null; // {device, mode, iface, prompt}
  let history = [];
  let back = -1;      // how far up the history the arrows are
  let sending = false;

  /** The consoled router or switch at the other end of a Work Laptop's console cable (or the device itself). */
  function target(dev) {
    if (dev.cli) return dev;
    const c = store.state.cables.find((k) => k.kind === 'console' && (k.a_device_id === dev.id || k.b_device_id === dev.id));
    if (!c) return null;
    const other = store.state.devices.find((d) => d.id === (c.a_device_id === dev.id ? c.b_device_id : c.a_device_id));
    return other?.cli ? other : null;
  }

  /** Opens a session from the picker: either end of the console cable. */
  store.openTerminal = async (deviceId) => {
    const dev = store.state.devices.find((d) => d.id === deviceId);
    const t = dev && target(dev);
    if (!t) {
      store.log('warn', dev?.item_code === 'work_laptop'
        ? 'Plug a console cable from this Work Laptop’s Console port into a Cisco router’s or switch’s Con0 port, then open the terminal.'
        : 'Only a Work Laptop can open a terminal. Buy one in the shop (B) and run a console cable from it to this device’s Con0 port.');
      return;
    }
    try {
      const res = await api.cli({ action: 'open', device: t.id });
      session = { device: res.device, mode: 'user', iface: null, prompt: res.prompt };
      history = [];
      back = -1;
      $('term-title').querySelector('span').textContent = `${res.device.label}: console`;
      out.textContent = `${res.device.name}\nConnected on the console port at 9600 baud.\nType ? for help. Press Enter to get started.\n\n`;
      checklist(res.checklist);
      prompt.textContent = session.prompt;
      panel.hidden = false;
      store.set({ picker: null, termOpen: true, uiFocus: true });
      input.value = '';
      input.focus();
      window.dispatchEvent(new CustomEvent('nf:terminal-open', { detail: { switch: res.device.switch } }));
    } catch (err) {
      store.log('error', err.message);
    }
  };

  function close() {
    if (panel.hidden) return;
    panel.hidden = true;
    session = null;
    store.set({ termOpen: false, uiFocus: !!(store.state.learnOpen || store.state.socialOpen || store.state.staffOpen) });
  }

  /** An interface this device really has, for the note: int vlan 1 on a switch, its first port on a router. */
  function shortIface() {
    if (session?.device.switch) return 'vlan 1';
    const dev = store.state.devices.find((d) => d.id === session?.device.id);
    const port = dev?.ports.find((p) => /^(Fa|Gi|Se)/.test(p.name))?.name || 'Gi0/0';
    return port.replace(/^Fa/, 'fa').replace(/^Gi/, 'g').replace(/^Se/, 's');
  }

  function checklist(list) {
    side.innerHTML = `<h3>Set it up</h3><ol class="term-check">${list.map((c) => `<li class="${c.done ? 'done' : ''}">
      <i class="ti ${c.done ? 'ti-circle-check' : 'ti-circle'}" aria-hidden="true"></i><span>${esc(c.label).replace(/\(([^)]*)\)/g, '<code>$1</code>')}</span></li>`).join('')}</ol>
      <p class="term-note">${list.every((c) => c.done)
        ? 'Configured. It boosts the devices behind it 25% more.'
        : 'Do all four and it boosts the devices behind it 25% more. Short forms work: <code>en</code>, <code>conf t</code>, <code>int ${esc(shortIface())}</code>, <code>no shut</code>.'}</p>`;
  }

  async function send(line) {
    if (!session || sending) return;
    sending = true;
    out.textContent += `${session.prompt}${session.prompt === 'Password:' ? '' : line}\n`;
    try {
      const res = await api.cli({ action: 'run', device: session.device.id, line, mode: session.mode, iface: session.iface });
      if (res.output) out.textContent += `${res.output}\n`;
      Object.assign(session, { mode: res.mode, iface: res.iface, prompt: res.prompt });
      checklist(res.checklist);
      if (res.state) store.applyServerState(res.state);
      if (res.configured) window.dispatchEvent(new CustomEvent('nf:configured', { detail: { id: session.device.id } }));
    } catch (err) {
      out.textContent += `% ${err.message}\n`;
    } finally {
      sending = false;
    }
    if (session) {
      prompt.textContent = session.prompt;
      input.type = session.prompt === 'Password:' ? 'password' : 'text';
      $('term-screen').scrollTop = $('term-screen').scrollHeight;
    }
  }

  $('term-form').addEventListener('submit', (e) => {
    e.preventDefault();
    const line = input.value;
    input.value = '';
    if (line.trim() && session?.prompt !== 'Password:') history = [line, ...history.filter((h) => h !== line)].slice(0, 40);
    back = -1;
    send(line);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === '?' && session?.prompt !== 'Password:') { // IOS answers ? straight away, without Enter
      e.preventDefault();
      const line = `${input.value}?`;
      send(line).then(() => { input.value = line.slice(0, -1); });
      return;
    }
    if (e.key === 'ArrowUp' || e.key === 'ArrowDown') {
      if (!history.length) return;
      e.preventDefault();
      back = Math.max(-1, Math.min(history.length - 1, back + (e.key === 'ArrowUp' ? 1 : -1)));
      input.value = back < 0 ? '' : history[back];
    }
  });
  input.addEventListener('input', () => { // a ? typed on a phone keyboard (no keydown with key '?')
    if (input.value.endsWith('?') && session && session.prompt !== 'Password:' && !sending) {
      const line = input.value;
      input.value = line.slice(0, -1);
      send(line).then(() => { input.value = line.slice(0, -1); });
    }
  });
  $('term-screen').addEventListener('click', () => { if (!getSelection().toString()) input.focus(); });
  $('term-close').addEventListener('click', close);
  addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) close(); });
}
