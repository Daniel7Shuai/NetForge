// The music button in the top bar: what's playing, play/pause, next track and volume.
// The player remembers on/off and volume in this browser.
import { api } from './api.js';
import { createMusic } from './music.js';

export async function mountMusic() {
  const btn = document.getElementById('music-btn'), panel = document.getElementById('music-panel');
  const toggle = document.getElementById('music-toggle'), nextBtn = document.getElementById('music-next');
  const vol = document.getElementById('music-volume');
  const titleEl = document.getElementById('music-title'), artistEl = document.getElementById('music-artist');

  let tracks = [];
  try { tracks = (await api.music()).tracks; } catch { /* no music list: the button just says so */ }

  const music = createMusic(tracks, render);
  vol.value = Math.round(music.volume * 100);
  render(music);

  function render(m) {
    btn.querySelector('i').className = `ti ${m.playing ? 'ti-music' : 'ti-music-off'}`;
    btn.classList.toggle('on', m.playing);
    btn.dataset.tip = !m.count ? 'No music in the play list yet.' : m.playing ? `Music: ${m.title}. Click for volume and tracks.` : 'Music is off. Click to turn it on.';
    titleEl.textContent = !m.count ? 'No tracks yet' : m.playing ? m.title : 'Music is off';
    artistEl.textContent = m.playing && m.artist ? m.artist : '';
    toggle.querySelector('i').className = `ti ${m.playing ? 'ti-player-pause' : 'ti-player-play'}`;
    toggle.setAttribute('aria-label', m.playing ? 'Pause music' : 'Play music');
    toggle.disabled = nextBtn.disabled = !m.count;
  }

  const close = () => { panel.hidden = true; btn.setAttribute('aria-expanded', 'false'); };
  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    panel.hidden = !panel.hidden;
    btn.setAttribute('aria-expanded', String(!panel.hidden));
  });
  document.addEventListener('click', (e) => { if (!panel.contains(e.target) && e.target !== btn) close(); });
  addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  toggle.addEventListener('click', () => music.toggle());
  nextBtn.addEventListener('click', () => music.next());
  vol.addEventListener('input', () => music.setVolume(vol.value / 100));
}
