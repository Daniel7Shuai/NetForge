// The end of the story: buying DataTech. Ada warns first that it ends the game; buying it plays
// the rescue cutscene, and the student can then go back to just before they bought it (the
// 999B comes back) from the end card or, later, from the menu.
import { api } from './api.js';
import { playEnding } from './cutscene.js';
import { playDialogue } from './dialogue.js';
import { loadStory } from './story.js';
import { fmt } from './money.js';

export function mountEnding(store) {
  const menuBtn = document.getElementById('ending-rewind');
  let ended = !!store.state.user?.gameEnded;
  menuBtn.hidden = !ended;

  store.subscribe((s) => {
    const now = !!s.user?.gameEnded;
    menuBtn.hidden = !now;
    if (now && !ended) { ended = true; finish(); } // just bought it
    else ended = now;
  });

  /** The shop's Buy button for DataTech lands here instead of buying straight away. */
  store.buyDataTech = async (row) => {
    const story = await loadStory();
    const choice = await playDialogue(story, [
      ['teacher', `This is it, {player}. ${fmt(row.price)} credits buys DataTech outright, and with it, your dad’s freedom.`],
      ['teacher', 'Buying it ends the game. Afterwards you can go back to just before this moment and keep playing, if you want.'],
    ], { player: store.state.user?.name || 'you', skipLabel: 'Not yet', choices: [{ label: 'Buy DataTech', value: 'buy' }, { label: 'Not yet', value: 'no' }] });
    if (choice === 'buy') store.buy(row.id);
  };

  async function finish() {
    const choice = await playEnding(store);
    if (choice === 'rewind') await rewind();
  }

  async function rewind() {
    try {
      store.applyServerState((await api.rewindEnding()).state);
    } catch (err) {
      store.log('error', err.message);
    }
  }
  menuBtn.addEventListener('click', () => {
    if (confirm('Go back to just before you bought DataTech? You get the credits back and DataTech goes back on sale.')) rewind();
  });
}
