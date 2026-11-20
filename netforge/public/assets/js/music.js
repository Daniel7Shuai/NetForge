// Background music. Five original chiptune tracks are composed right here and played with the
// Web Audio API (no audio files, no copyright questions). Staff can add uploaded tracks on the
// Music page; those play through the same volume control. The playlist comes from api/music.php.

/* ---------------- the five built-in tracks ---------------- */
// Each one: a chord progression (one chord per bar), bass and arpeggio styles built from it,
// a drum groove, and a hand-written 4-bar melody (16 steps a bar: a note, "-" to hold, "." for rest).
export const SYNTH_TRACKS = {
  'garage-boot': {
    bpm: 84, prog: ['Am', 'F', 'C', 'G'], bass: 'lofi', arp: 'eighths', drums: 'lofi', lead: 'square',
    melody: [
      'E5 - - - C5 - - - . . A4 - - - . .',
      'A4 - - - C5 - F5 - - - E5 - - - . .',
      'G4 - - - . . E5 - D5 - C5 - - - . .',
      'B4 - - - D5 - - - G4 - - - . . . .',
    ],
  },
  'packet-flow': {
    bpm: 118, prog: ['C', 'G', 'Am', 'F'], bass: 'drive', arp: 'sixteenths', drums: 'drive', lead: 'square',
    melody: [
      'E5 - G5 - E5 - C5 - D5 - E5 - - - . .',
      'D5 - B4 - G4 - B4 - D5 - - - G5 - - -',
      'C5 - E5 - A5 - - - G5 - E5 - C5 - - -',
      'A4 - C5 - F5 - - - E5 - - - D5 - . .',
    ],
  },
  'night-shift': {
    bpm: 70, prog: ['Dm', 'Bb', 'F', 'C'], bass: 'pad', arp: 'pad', drums: 'ambient', lead: 'triangle',
    melody: [
      'A4 - - - - - - - F4 - - - - - - -',
      'D5 - - - - - - - F5 - - - - - - -',
      'C5 - - - - - - - A4 - - - - - - -',
      'G4 - - - - - - - E4 - - - . . . .',
    ],
  },
  'server-hum': {
    bpm: 100, prog: ['Em', 'C', 'G', 'D'], bass: 'groove', arp: 'eighths', drums: 'groove', lead: 'square',
    melody: [
      '. . E5 - G5 - B5 - - - G5 - E5 - . .',
      '. . E5 - G5 - C6 - - - B5 - G5 - . .',
      '. . D5 - G5 - B5 - - - A5 - G5 - . .',
      'F#5 - - - E5 - - - D5 - - - A4 - - -',
    ],
  },
  firewall: {
    bpm: 132, prog: ['Am', 'Am', 'F', 'E'], bass: 'tense', arp: 'sixteenths', drums: 'tense', lead: 'sawtooth',
    melody: [
      'A4 . A4 . C5 . A4 . E5 - D5 - C5 - B4 -',
      'A4 . A4 . C5 . E5 . A5 - - - G5 - E5 -',
      'F5 - - - E5 - - - C5 - - - A4 - - -',
      'G#4 - - - B4 - - - E5 - - - D5 - B4 -',
    ],
  },
};

const NAMES = { C: 0, 'C#': 1, Db: 1, D: 2, 'D#': 3, Eb: 3, E: 4, F: 5, 'F#': 6, Gb: 6, G: 7, 'G#': 8, Ab: 8, A: 9, 'A#': 10, Bb: 10, B: 11 };
const midi = (note) => { const m = /^([A-G][#b]?)(\d)$/.exec(note); return (Number(m[2]) + 1) * 12 + NAMES[m[1]]; };
const hz = (m) => 440 * 2 ** ((m - 69) / 12);
/** Chord tones (MIDI) for "Am", "F", "Bb", "F#m"... rooted in the given octave. */
function chord(name, octave) {
  const m = /^([A-G][#b]?)(m?)$/.exec(name);
  const root = (octave + 1) * 12 + NAMES[m[1]];
  return [root, root + (m[2] ? 3 : 4), root + 7];
}

// [step, chord tone (0 root, 1 third, 2 fifth, 3 root up an octave), length in steps]
const BASS = {
  lofi:   [[0, 0, 6], [7, 0, 2], [10, 2, 4]],
  drive:  [0, 2, 4, 6, 8, 10, 12, 14].map((s) => [s, s === 12 ? 2 : 0, 2]),
  pad:    [[0, 0, 16]],
  groove: [[0, 0, 3], [3, 0, 1], [6, 2, 2], [8, 0, 3], [11, 0, 1], [14, 1, 2]],
  tense:  [0, 2, 3, 4, 6, 8, 10, 11, 12, 14].map((s) => [s, 0, 1]),
};
const ARP = {
  eighths:    [0, 1, 2, 1, 0, 1, 2, 1].map((t, i) => [i * 2, t, 2]),
  sixteenths: Array.from({ length: 16 }, (_, i) => [i, [0, 1, 2, 3][i % 4], 1]),
  pad:        [[0, 0, 16], [0, 1, 16], [0, 2, 16]],
};
const DRUMS = {
  lofi:    { kick: 'x......x..x.....', snare: '....x.......x...', hat: 'x.x.x.x.x.x.x.x.' },
  drive:   { kick: 'x...x...x...x...', snare: '....x.......x...', hat: '.x.x.x.x.x.x.x.x' },
  ambient: { kick: '................', snare: '................', hat: '........x.......' },
  groove:  { kick: 'x.....x...x.....', snare: '....x.......x..x', hat: 'x.xxx.x.x.xxx.x.' },
  tense:   { kick: 'x..x..x...x..x..', snare: '....x.......x..x', hat: 'xxxxxxxxxxxxxxxx' },
};

/** Turns a track into one loop of timed notes. */
function compose(t) {
  const notes = [], hits = [];
  t.prog.forEach((name, bar) => {
    const base = bar * 16;
    const bass = chord(name, 2), mid = chord(name, 4);
    for (const [s, deg, len] of BASS[t.bass]) notes.push({ step: base + s, len, freq: hz(deg === 3 ? bass[0] + 12 : bass[deg]), wave: 'triangle', gain: t.bass === 'pad' ? 0.13 : 0.17, part: 'bass' });
    for (const [s, deg, len] of ARP[t.arp]) notes.push({ step: base + s, len, freq: hz(deg === 3 ? mid[0] + 12 : mid[deg]), wave: t.arp === 'pad' ? 'sine' : 'triangle', gain: t.arp === 'pad' ? 0.045 : 0.05, part: 'arp' });
    const tokens = t.melody[bar].trim().split(/\s+/);
    tokens.forEach((tok, i) => {
      if (tok === '.' || tok === '-') return;
      let len = 1;
      while (tokens[i + len] === '-') len++;
      notes.push({ step: base + i, len, freq: hz(midi(tok)), wave: t.lead, gain: t.lead === 'triangle' ? 0.09 : 0.045, part: 'lead' });
    });
    const d = DRUMS[t.drums];
    for (let s = 0; s < 16; s++) {
      for (const kind of ['kick', 'snare', 'hat']) if (d[kind][s] === 'x') hits.push({ step: base + s, kind });
    }
  });
  return { notes, hits, steps: t.prog.length * 16 };
}

/* ---------------- the engine ---------------- */

let ctx = null, master = null, noise = null;
function audio() {
  if (ctx) return ctx;
  ctx = new (window.AudioContext || window.webkitAudioContext)();
  const comp = ctx.createDynamicsCompressor();
  const tone = ctx.createBiquadFilter();
  tone.type = 'lowpass'; tone.frequency.value = 9000;
  master = ctx.createGain();
  master.gain.value = 0.5;
  master.connect(tone).connect(comp).connect(ctx.destination);
  noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
  const data = noise.getChannelData(0);
  for (let i = 0; i < data.length; i++) data[i] = Math.random() * 2 - 1;
  return ctx;
}

function playNote(out, n, t, dur) {
  const osc = ctx.createOscillator(), g = ctx.createGain();
  osc.type = n.wave; osc.frequency.value = n.freq;
  let node = osc;
  if (n.wave === 'square' || n.wave === 'sawtooth') { // soften the bright waves
    const f = ctx.createBiquadFilter(); f.type = 'lowpass'; f.frequency.value = 2400;
    node = osc.connect(f);
  }
  node.connect(g).connect(out);
  const end = t + dur;
  g.gain.setValueAtTime(0.0001, t);
  g.gain.linearRampToValueAtTime(n.gain, t + 0.012);
  g.gain.exponentialRampToValueAtTime(Math.max(0.0001, n.gain * 0.55), t + Math.min(0.25, dur * 0.6));
  g.gain.setValueAtTime(Math.max(0.0001, n.gain * 0.55), Math.max(t + 0.02, end - 0.04));
  g.gain.linearRampToValueAtTime(0.0001, end);
  osc.start(t); osc.stop(end + 0.02);
}

function playHit(out, kind, t) {
  if (kind === 'kick') {
    const o = ctx.createOscillator(), g = ctx.createGain();
    o.frequency.setValueAtTime(140, t); o.frequency.exponentialRampToValueAtTime(45, t + 0.12);
    g.gain.setValueAtTime(0.45, t); g.gain.exponentialRampToValueAtTime(0.001, t + 0.2);
    o.connect(g).connect(out); o.start(t); o.stop(t + 0.22);
    return;
  }
  const src = ctx.createBufferSource(), f = ctx.createBiquadFilter(), g = ctx.createGain();
  src.buffer = noise;
  f.type = 'highpass'; f.frequency.value = kind === 'snare' ? 1400 : 7000;
  const peak = kind === 'snare' ? 0.16 : 0.035, len = kind === 'snare' ? 0.14 : 0.04;
  g.gain.setValueAtTime(peak, t); g.gain.exponentialRampToValueAtTime(0.001, t + len);
  src.connect(f).connect(g).connect(out); src.start(t); src.stop(t + len + 0.02);
}

/**
 * Plays one built-in track for about two minutes, then calls onEnd. Returns a stop function.
 * The melody rests on the first pass of every four, so the tune breathes.
 */
function playSynth(key, onEnd) {
  const t = SYNTH_TRACKS[key];
  if (!t) { onEnd?.(); return () => {}; }
  audio();
  const loop = compose(t);
  const stepDur = 60 / t.bpm / 4;
  const loops = Math.max(4, Math.round(120 / (loop.steps * stepDur)));
  const out = ctx.createGain();
  out.connect(master);
  let step = 0, pass = 0, next = ctx.currentTime + 0.1, stopped = false;
  const byStep = new Map();
  for (const n of loop.notes) (byStep.get(n.step) || byStep.set(n.step, []).get(n.step)).push(n);
  for (const h of loop.hits) (byStep.get(h.step) || byStep.set(h.step, []).get(h.step)).push(h);

  const timer = setInterval(() => {
    while (!stopped && next < ctx.currentTime + 0.15) {
      for (const e of byStep.get(step) || []) {
        if (e.kind) playHit(out, e.kind, next);
        else if (!(e.part === 'lead' && pass % 4 === 0)) playNote(out, e, next, e.len * stepDur);
      }
      next += stepDur;
      if (++step >= loop.steps) {
        step = 0;
        if (++pass >= loops) { finish(); onEnd?.(); return; }
      }
    }
  }, 25);
  function finish() {
    if (stopped) return;
    stopped = true;
    clearInterval(timer);
    out.gain.setTargetAtTime(0, ctx.currentTime, 0.15);
    setTimeout(() => out.disconnect(), 800);
  }
  return finish;
}

function playFile(url, onEnd) {
  audio();
  const el = new Audio(url);
  el.crossOrigin = 'use-credentials';
  const src = ctx.createMediaElementSource(el);
  src.connect(master);
  el.addEventListener('ended', () => { src.disconnect(); onEnd?.(); });
  el.addEventListener('error', () => { src.disconnect(); onEnd?.(); }); // a missing file skips on
  el.play().catch(() => {});
  return () => { el.pause(); src.disconnect(); };
}

/* ---------------- previews for the staff Music page ---------------- */
let previewStop = null;
export function previewSynth(key) {
  stopPreview();
  audio().resume();
  previewStop = playSynth(key, () => { previewStop = null; });
}
export function stopPreview() { previewStop?.(); previewStop = null; }

/* ---------------- the in-game player ---------------- */

const PREFS = 'nf.music';
function loadPrefs() {
  try { return { on: true, volume: 0.45, ...JSON.parse(localStorage.getItem(PREFS) || '{}') }; } catch { return { on: true, volume: 0.45 }; }
}
function savePrefs(p) { try { localStorage.setItem(PREFS, JSON.stringify(p)); } catch { /* private mode: just don't remember */ } }

/**
 * The game's music: plays the playlist in order and loops it. Starts on the player's first
 * click or key press (browsers don't allow sound before that), if music is switched on.
 */
export function createMusic(tracks, onChange = () => {}) {
  const prefs = loadPrefs();
  let index = Math.floor(Math.random() * Math.max(1, tracks.length)); // start somewhere different each visit
  let stop = null, playing = false;

  const current = () => tracks[index] || null;
  function startTrack() {
    stop?.();
    const t = current();
    if (!t) return;
    const advance = () => { index = (index + 1) % tracks.length; if (playing) startTrack(); };
    stop = t.kind === 'file' ? playFile(t.url, advance) : playSynth(t.synth, advance);
    onChange(api);
  }
  const api = {
    get on() { return prefs.on; },
    get playing() { return playing; },
    get volume() { return prefs.volume; },
    get title() { return current()?.title || ''; },
    get artist() { return current()?.artist || ''; },
    get count() { return tracks.length; },
    play() {
      if (!tracks.length) return;
      audio().resume();
      master.gain.value = prefs.volume;
      playing = true; prefs.on = true; savePrefs(prefs);
      startTrack();
    },
    pause() {
      playing = false; prefs.on = false; savePrefs(prefs);
      stop?.(); stop = null;
      onChange(api);
    },
    toggle() { playing ? api.pause() : api.play(); },
    next() { index = (index + 1) % Math.max(1, tracks.length); if (playing) startTrack(); else onChange(api); },
    setVolume(v) {
      prefs.volume = Math.max(0, Math.min(1, v)); savePrefs(prefs);
      if (master) master.gain.setTargetAtTime(prefs.volume, ctx.currentTime, 0.05);
      onChange(api);
    },
  };
  // start on the first interaction, if the player left music on
  const kick = () => {
    removeEventListener('pointerdown', kick, true);
    removeEventListener('keydown', kick, true);
    if (prefs.on && !playing) api.play();
  };
  addEventListener('pointerdown', kick, true);
  addEventListener('keydown', kick, true);
  return api;
}
