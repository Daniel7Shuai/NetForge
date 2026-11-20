// The end of the story: buying DataTech. Ada warns first that it ends the game; buying it plays
// the rescue cutscene, then the end card asks once: go back to just before buying it (the 999B
// comes back) or stay at the ending. If the game is closed before choosing, the end card comes
// back next time; once the student stays, that's final.
import { api } from './api.js';
import { playEnding, endCard } from './cutscene.js';
import { playDialogue } from './dialogue.js';
import { loadStory } from './story.js';
import { fmt } from './money.js';

const STAYED = 'ending-stay'; // seen flag: the student chose to stay at the ending

export async function mountEnding(store) {
  let ended = !!store.state.user?.gameEnded;
  const story = await loadStory();
  const player = () => store.state.user?.name || 'you';

  store.subscribe((s) => {
    const now = !!s.user?.gameEnded;
    if (now && !ended) { ended = true; finish(); } // just bought it
    else ended = now;
  });

  /** The shop's Buy button for DataTech lands here instead of buying straight away. */
  store.buyDataTech = async (row) => {
    const choice = await playDialogue(story, [
      ['teacher', `This is it, {player}. ${fmt(row.price)} credits buys DataTech outright, and with it, your dad’s freedom.`],
      ['teacher', 'Buying it ends the game. When it’s over, you can choose to go back to just before this moment and keep playing.'],
    ], { player: player(), skipLabel: 'Not yet', choices: [{ label: 'Buy DataTech', value: 'buy' }, { label: 'Not yet', value: 'no' }] });
    if (choice === 'buy') store.buy(row.id);
  };

  async function finish() {
    choose(await playEnding(store));
  }

  async function choose(choice) {
    if (choice !== 'rewind') { api.seen(STAYED).catch(() => {}); return; }
    try {
      store.applyServerState((await api.rewindEnding()).state);
    } catch (err) {
      store.log('error', err.message);
    }
  }

  // bought it, but closed the game before choosing: ask again
  if (ended && !(story.tutorial.seen || []).includes(STAYED)) endCard(player()).then(choose);
}
