// Boot: load state from the server, mount the HUD, start Phaser.
import { createStore } from './store.js';
import { mountHud } from './hud.js';
import { mountLearn } from './learn.js';
import { mountSocial } from './social.js';
import { mountDefence } from './defence.js';
import { mountEconomy } from './economy.js';
import { mountTooltips } from './tooltip.js';
import { MapScene } from './game/MapScene.js';
import { TILE, COLS, ROWS, setLocation } from './game/layout.js';

const store = createStore();

try {
  await store.load();
} catch (err) {
  document.getElementById('obj-title').textContent = err.message;
  throw err;
}

setLocation(store.state.user.location);
mountHud(store);
mountEconomy(store);
mountTooltips();
mountLearn(store);
mountSocial(store);
mountDefence(store);

new Phaser.Game({
  type: Phaser.CANVAS,
  parent: 'game',
  width: COLS * TILE,
  height: ROWS * TILE,
  pixelArt: true,
  backgroundColor: '#060a12',
  scale: { mode: Phaser.Scale.FIT, autoCenter: Phaser.Scale.CENTER_BOTH },
  scene: [new MapScene(store)],
  banner: false,
});
