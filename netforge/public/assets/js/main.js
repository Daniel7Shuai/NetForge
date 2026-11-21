// Boot: load state from the server, mount the HUD, start Phaser.
import { createStore } from './store.js';
import { mountHud } from './hud.js';
import { mountLearn } from './learn.js';
import { mountSocial } from './social.js';
import { mountDefence } from './defence.js';
import { mountEconomy } from './economy.js';
import { mountTooltips } from './tooltip.js';
import { mountTutorial } from './tutorial.js';
import { mountMusic } from './musicui.js';
import { mountMoments } from './moments.js';
import { mountEvents } from './events.js';
import { mountEnding } from './ending.js';
import { mountRackView } from './rackview.js';
import { mountTodo } from './todo.js';
import { mountFacility } from './facility.js';
import './game/story-art.js'; // the story's characters and their portraits
import './game/device-art.js'; // sprites for the Packet Tracer device catalogue
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
mountMusic();
mountLearn(store);
mountSocial(store);
mountDefence(store);
mountRackView(store);
mountTodo(store);
mountFacility(store);

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

// after the map exists: the tutorial walks new students through their first network
mountTutorial(store);
// the characters keep turning up after it: tips along the way, the broker, investors
mountMoments(store);
mountEvents(store);
mountEnding(store);
