// Lecturer pages: live lesson preview and per-type help in the question editor.
import { renderLesson } from './learn.js';

/* lesson preview, rendered exactly as students see it */
const src = document.getElementById('lesson-src');
const preview = document.getElementById('lesson-preview');
if (src && preview) {
  const draw = () => { preview.innerHTML = src.value.trim() ? renderLesson(src.value) : '<p class="empty">Start typing to see the lesson.</p>'; };
  src.addEventListener('input', draw);
  draw();
}

/* question editor: explain the choices/answer format for the chosen type */
const HELP = {
  mcq: {
    prompt: 'The question students answer.',
    choices: 'One option per line (2 to 6). Students see them shuffled.',
    answer: 'The correct option’s number (1 = first line) or its exact text.',
    showAnswer: true,
  },
  matching: {
    prompt: 'For example: Match each device to its role.',
    choices: 'One pair per line, written as left = right (2 to 6 pairs). The right side is shuffled.',
    answer: '', showAnswer: false,
  },
  ordering: {
    prompt: 'For example: Put these commands in order.',
    choices: 'One item per line, in the correct order (2 to 8). Students see them shuffled.',
    answer: '', showAnswer: false,
  },
  fill: {
    prompt: 'Write ___ (three underscores) for each blank, up to 5.',
    choices: 'Not used for this type.',
    answer: 'One answer per blank, separated by |. Accept other spellings with /, e.g. isp / internet service provider.',
    showAnswer: true,
  },
  blitz: {
    prompt: 'A short name, e.g. Binary to decimal, 8 bits. Each serve generates a new number.',
    choices: 'Settings: mode=bin2dec or mode=dec2bin, bits=4 to 8, seconds=5 to 120.',
    answer: '', showAnswer: false,
  },
  subnet: {
    prompt: 'A short name, e.g. Network address, /24 to /30. Each serve generates a new address.',
    choices: 'Settings: mode=hosts, mask, network or broadcast; min and max prefix from 8 to 30.',
    answer: '', showAnswer: false,
  },
};
const qtype = document.getElementById('qtype');
if (qtype) {
  const set = (id, text) => { const el = document.getElementById(id); if (el) el.textContent = text; };
  const apply = () => {
    const h = HELP[qtype.value];
    set('help-prompt', h.prompt);
    set('help-choices', h.choices);
    set('help-answer', h.answer);
    document.getElementById('answer-wrap').hidden = !h.showAnswer;
    document.getElementById('choices').disabled = qtype.value === 'fill';
  };
  qtype.addEventListener('change', apply);
  apply();

  // only offer levels from the chosen world
  const world = document.getElementById('world');
  const level = document.getElementById('level');
  const filter = () => {
    for (const o of level.options) {
      if (!o.dataset.world) continue;
      o.hidden = o.dataset.world !== world.value;
      if (o.hidden && o.selected) level.value = '0';
    }
  };
  world.addEventListener('change', filter);
  filter();
}
