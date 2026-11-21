// Pixel art for the Packet Tracer device catalogue: 1U rack units, the hub, access points,
// modems, phones, tablets, printers, TVs, IP phones and IoT devices. Each is added to SPRITES,
// so the map, the inventory and the shop draw it like any other device.
import { C, SPRITES } from './art.js';

const R = (c, x, y, w, h, col) => { c.fillStyle = col; c.fillRect(x, y, w, h); };
const shadow = (c, x, y, w) => R(c, x, y, w, 2, 'rgba(0,0,0,.45)');

/* ---------- 1U rack units: one tile tall ---------- */
// kind: switch (rows of ports), router (a few ports and module bays), wlc / ctl / asa (accent stripe)
function unit1u(c, kind) {
  const accent = { switch: '#1590a6', router: '#a8661d', wlc: '#7a5cc8', ctl: '#2b8a54', asa: '#b8232f' }[kind];
  shadow(c, 0, 10, 16);
  R(c, 0, 0, 16, 10, C.k); R(c, 1, 1, 14, 8, '#1c2638'); R(c, 1, 1, 14, 1, '#33446a');
  R(c, 1, 1, 1, 8, '#2a3850'); R(c, 14, 1, 1, 8, '#2a3850'); // rack ears
  if (kind === 'switch') {
    for (let i = 0; i < 6; i++) { R(c, 3 + i * 2, 4, 1, 2, '#05070d'); R(c, 3 + i * 2, 7, 1, 1, '#05070d'); }
    R(c, 3, 2, 10, 1, accent);
  } else if (kind === 'router') {
    R(c, 3, 3, 4, 4, '#111a28'); R(c, 8, 3, 4, 4, '#111a28'); // module bays
    R(c, 4, 4, 2, 1, C.metal2); R(c, 9, 4, 2, 1, C.metal2);
    R(c, 3, 8, 8, 1, accent);
  } else {
    R(c, 3, 3, 10, 2, accent); R(c, 3, 6, 2, 2, '#05070d'); R(c, 6, 6, 2, 2, '#05070d');
  }
}
const rackUnit = (kind, leds) => ({ w: 16, h: 12, off: [0, 4], box: [16, 10], port: [8, 9], draw: (c) => unit1u(c, kind), leds });

/* ---------- small devices that stand on a shelf, desk or the floor ---------- */
function hub(c) {
  shadow(c, 1, 8, 13);
  R(c, 0, 2, 14, 7, C.k); R(c, 1, 3, 12, 5, '#5b6b80'); R(c, 1, 3, 12, 1, '#7a8aa0');
  for (let i = 0; i < 4; i++) R(c, 2 + i * 3, 5, 2, 2, '#1c2638');
}
function accessPoint(c) {
  shadow(c, 2, 14, 9);
  R(c, 5, 6, 2, 8, C.metal2); R(c, 3, 13, 6, 1, C.metal3);   // stand
  R(c, 0, 2, 12, 5, C.k); R(c, 1, 3, 10, 3, '#e3eaf1'); R(c, 1, 3, 10, 1, '#ffffff'); // the disc
  R(c, 2, 0, 1, 3, C.k); R(c, 9, 0, 1, 3, C.k);               // antennas
}
function modem(c) {
  shadow(c, 1, 11, 9);
  R(c, 1, 1, 8, 11, C.k); R(c, 2, 2, 6, 9, '#2a3446'); R(c, 2, 2, 6, 1, '#465672');
  R(c, 3, 9, 4, 1, '#141a26');
}
function stand(c, top) { // a small charging stand, with the device on top
  shadow(c, 1, 14, 10);
  R(c, 1, 11, 10, 3, C.k); R(c, 2, 11, 8, 2, '#3a4660');
  top(c);
}
function phone(c) {
  stand(c, () => { R(c, 3, 1, 6, 10, C.k); R(c, 4, 2, 4, 7, '#0a2a33'); R(c, 4, 3, 3, 1, C.cyan); R(c, 4, 5, 2, 1, '#1590a6'); R(c, 5, 10, 2, 1, '#3a4660'); });
}
function tablet(c) {
  stand(c, () => { R(c, 1, 2, 10, 9, C.k); R(c, 2, 3, 8, 7, '#0a2a33'); R(c, 3, 4, 5, 1, C.cyan); R(c, 3, 6, 6, 1, '#1590a6'); R(c, 3, 8, 3, 1, C.lime); });
}
function printer(c) {
  shadow(c, 1, 12, 13);
  R(c, 0, 4, 14, 8, C.k); R(c, 1, 5, 12, 6, '#c9d3de'); R(c, 1, 5, 12, 1, '#eef3f7');
  R(c, 3, 1, 8, 4, '#f4f6fa'); R(c, 3, 1, 8, 1, '#ffffff');     // paper
  R(c, 2, 9, 10, 1, '#5b6b80'); R(c, 11, 6, 1, 1, C.lime);
}
function tv(c) {
  shadow(c, 3, 14, 10);
  R(c, 0, 0, 16, 10, C.k); R(c, 1, 1, 14, 8, '#06323d');
  R(c, 2, 2, 7, 1, C.cyan); R(c, 2, 4, 10, 1, '#1590a6'); R(c, 2, 6, 5, 1, C.amber);
  R(c, 7, 10, 2, 3, C.metal2); R(c, 4, 13, 8, 1, C.metal3);
}
function ipPhone(c) {
  shadow(c, 1, 10, 12);
  R(c, 0, 3, 13, 7, C.k); R(c, 1, 4, 11, 5, '#2a3446'); R(c, 1, 4, 11, 1, '#465672');
  R(c, 6, 5, 5, 3, '#0a2a33'); R(c, 7, 6, 3, 1, C.cyan);        // screen
  R(c, 1, 1, 4, 3, C.k); R(c, 1, 2, 4, 1, '#3a4660');           // handset
  for (let i = 0; i < 3; i++) R(c, 2, 5 + i, 1, 1, '#5b6b80');
}

/* ---------- IoT ---------- */
function lamp(c) {
  shadow(c, 3, 14, 6);
  R(c, 5, 5, 1, 9, C.metal2); R(c, 3, 13, 5, 1, C.metal3);
  R(c, 2, 1, 7, 4, C.k); R(c, 3, 2, 5, 2, '#ffe2a8'); R(c, 1, 5, 9, 2, 'rgba(255,226,168,.18)');
}
function plug(c) {
  shadow(c, 3, 12, 6);
  R(c, 2, 5, 7, 7, C.k); R(c, 3, 6, 5, 5, '#e3eaf1'); R(c, 4, 8, 1, 2, C.k); R(c, 6, 8, 1, 2, C.k); R(c, 7, 6, 1, 1, C.lime);
}
function thermostat(c) {
  shadow(c, 3, 14, 6);
  R(c, 5, 8, 1, 6, C.metal2); R(c, 3, 13, 5, 1, C.metal3);
  c.fillStyle = C.k; c.beginPath(); c.arc(5.5, 5, 4.5, 0, 7); c.fill();
  c.fillStyle = '#e3eaf1'; c.beginPath(); c.arc(5.5, 5, 3.5, 0, 7); c.fill();
  R(c, 4, 4, 3, 2, C.amber);
}
function camera(c) {
  shadow(c, 3, 14, 6);
  R(c, 5, 5, 1, 9, C.metal2); R(c, 3, 13, 5, 1, C.metal3);
  R(c, 1, 1, 10, 5, C.k); R(c, 2, 2, 7, 3, '#e3eaf1'); R(c, 9, 2, 2, 3, '#1c2638'); R(c, 10, 3, 1, 1, '#ff2a3a');
}
function sensor(c) {
  shadow(c, 3, 14, 6);
  R(c, 5, 7, 1, 7, C.metal2); R(c, 3, 13, 5, 1, C.metal3);
  R(c, 2, 3, 7, 5, C.k); R(c, 3, 4, 5, 3, '#2b8a74'); R(c, 6, 0, 1, 4, C.k); R(c, 4, 5, 1, 1, C.lime);
}
function streetLight(c) { // tall: drawn from 8px above its tile
  shadow(c, 4, 22, 6);
  R(c, 6, 3, 2, 19, '#3a4660'); R(c, 4, 21, 6, 1, C.metal3);
  R(c, 6, 1, 8, 2, '#3a4660'); R(c, 10, 3, 5, 2, '#ffe2a8'); R(c, 8, 5, 9, 3, 'rgba(255,226,168,.15)');
}
function trafficLight(c) {
  shadow(c, 3, 22, 6);
  R(c, 5, 12, 2, 10, '#3a4660'); R(c, 3, 21, 6, 1, C.metal3);
  R(c, 2, 0, 8, 13, C.k); R(c, 3, 1, 6, 11, '#1b1f27');
  R(c, 4, 2, 4, 3, '#ff2a3a'); R(c, 4, 5, 4, 3, '#5a4818'); R(c, 4, 8, 4, 3, '#1a4a14');
}
function plc(c) {
  shadow(c, 1, 15, 14);
  R(c, 0, 0, 14, 15, C.k); R(c, 1, 1, 12, 13, '#8a8f98'); R(c, 1, 1, 12, 1, '#b8bec8');
  R(c, 2, 3, 10, 3, '#2a2e36'); R(c, 3, 4, 3, 1, C.lime); R(c, 7, 4, 3, 1, C.amber);
  R(c, 2, 8, 10, 1, '#ff8a1f'); R(c, 2, 10, 4, 3, '#5a5f68'); R(c, 8, 10, 4, 3, '#5a5f68');
}

/* ---------- Misc rack gear (shown in shop icons; on the map it lives inside its rack) ---------- */
function unitBox(c, u, body, trim, detail) { // a rack unit u tall, 16 wide, 4px per U
  const h = u * 4 + 2;
  R(c, 0, 0, 16, h, C.k); R(c, 1, 1, 14, h - 2, body); R(c, 1, 1, 14, 1, trim);
  detail(c, h);
}
const misc = (u, body, trim, detail) => ({ w: 16, h: u * 4 + 2, off: [0, 0], box: [16, u * 4 + 2], port: [8, u * 4], draw: (c) => unitBox(c, u, body, trim, detail), leds: [] });
const MISC = {
  pdu1u: misc(2, '#1b1f27', '#3a4660', (c) => { for (let i = 0; i < 5; i++) R(c, 3 + i * 2, 4, 1, 2, '#05070d'); R(c, 13, 3, 1, 1, '#ff2a3a'); }),
  ups3u: misc(3, '#14171e', '#2a3446', (c, h) => { R(c, 3, 3, 6, 3, '#0a2a33'); R(c, 4, 4, 3, 1, C.lime); R(c, 10, 3, 3, h - 6, '#2a3446'); }),
  patch1u: misc(2, '#c9d3de', '#eef3f7', (c) => { for (let i = 0; i < 6; i++) R(c, 2 + i * 2, 4, 1, 2, '#1c2638'); }),
  cablemgmt1u: misc(2, '#1b1f27', '#2a3446', (c) => { for (let i = 0; i < 5; i++) R(c, 2 + i * 3, 2, 2, 5, '#3a4660'); R(c, 1, 5, 14, 1, C.cyan); }),
  blank1u: misc(2, '#22262e', '#2e333d', (c) => { R(c, 3, 4, 10, 1, '#191c22'); }),
  kvm1u: misc(2, '#1c2638', '#33446a', (c) => { R(c, 3, 3, 4, 3, '#0a2a33'); R(c, 4, 4, 2, 1, C.cyan); for (let i = 0; i < 3; i++) R(c, 9 + i * 2, 4, 1, 2, '#05070d'); }),
  lb1u: misc(2, '#1c2638', '#33446a', (c) => { R(c, 3, 3, 10, 1, '#d9a03a'); for (let i = 0; i < 4; i++) R(c, 4 + i * 2, 5, 1, 2, '#05070d'); }),
  nas2u: misc(3, '#2a3446', '#465672', (c, h) => { for (let i = 0; i < 4; i++) R(c, 2 + i * 3, 3, 2, h - 6, '#141a26'); R(c, 14, 3, 1, 1, C.lime); }),
  san3u: misc(4, '#1b1f27', '#3a4660', (c, h) => { for (let r = 0; r < 3; r++) for (let i = 0; i < 6; i++) R(c, 2 + i * 2, 3 + r * 4, 1, 3, '#2a3446'); R(c, 14, 3, 1, 1, C.amber); }),
  module: { w: 16, h: 10, off: [0, 6], box: [16, 10], port: [8, 8], leds: [], draw: (c) => { R(c, 0, 1, 16, 8, C.k); R(c, 1, 2, 14, 6, '#2c3b56'); R(c, 3, 4, 3, 2, '#05070d'); R(c, 8, 4, 3, 2, C.amber); R(c, 1, 8, 14, 1, '#b08a2e'); } },
  wificard: { w: 16, h: 12, off: [0, 4], box: [16, 12], port: [8, 10], leds: [], draw: (c) => { R(c, 0, 4, 16, 7, C.k); R(c, 1, 5, 14, 5, '#22301f'); R(c, 2, 10, 10, 2, '#b08a2e'); R(c, 12, 0, 1, 5, C.k); R(c, 14, 1, 1, 4, C.k); R(c, 4, 6, 4, 2, '#3b5a34'); } },
};

/* ---------- the rack cabinet on the map: 12U, each unit a band in its own colour ---------- */
export const RACK_U = 12;
// what each kind of rack gear looks like from across the room: [body, highlight]
const BAND = {
  switch1u: ['#1c3a48', '#2be3ff'], router1u: ['#3a2a14', '#ffb43d'], rack2u: ['#2a3446', '#7cff6b'], gpurig: ['#1d2a1c', '#7cff6b'],
  pdu1u: ['#2a1418', '#ff2a3a'], ups3u: ['#14171e', '#7cff6b'], patch1u: ['#9aa7b6', '#eef3f7'], cablemgmt1u: ['#1b1f27', '#2be3ff'],
  blank1u: ['#1a1d23', '#22262e'], kvm1u: ['#1c2638', '#2be3ff'], lb1u: ['#2e2614', '#d9a03a'], nas2u: ['#2a3446', '#9fb3c8'],
  san3u: ['#1b1f27', '#ffb43d'], wlc1u: ['#2a2244', '#9c8cff'], ctl1u: ['#14301f', '#7cff6b'], asa1u: ['#3a1626', '#ff3d9a'], firewall: ['#3a1626', '#ff3d9a'],
};
/** Draws a 16x33 rack cabinet. units: [{u, size, sprite}], powered: has a PDU. */
export function rackCabinet(c, units, powered) {
  R(c, 0, 31, 16, 2, 'rgba(0,0,0,.5)');
  R(c, 0, 0, 16, 31, C.k); R(c, 1, 1, 14, 29, '#0b111b');
  R(c, 1, 1, 14, 2, '#2c3b56'); R(c, 1, 1, 1, 29, '#2c3b56'); R(c, 14, 1, 1, 29, '#2c3b56');
  for (let u = 0; u < RACK_U; u++) R(c, 2, 28 - u * 2, 1, 1, '#1d2a3c'); // rail holes
  for (const d of units) {
    const [body, hi] = BAND[d.sprite] || ['#2a3446', '#9fb3c8'];
    const top = 29 - (d.u + d.size - 1) * 2, h = d.size * 2;
    R(c, 3, top, 10, h, body); R(c, 3, top, 10, 1, hi);
    if (powered && d.sprite !== 'blank1u' && d.sprite !== 'cablemgmt1u') R(c, 11, top + h - 1, 1, 1, hi === '#22262e' ? body : '#7cff6b');
  }
  R(c, 2, 3, 12, 1, powered ? '#3a1418' : '#141820'); if (powered) R(c, 12, 3, 1, 1, '#ff2a3a'); // the PDU's power strip
}

// off: where the sprite sits in its tile; box: visible size; port: where cables plug in
const one = (w, h, draw, leds = [], port) => ({ w, h, off: [Math.floor((16 - w) / 2), 16 - h], box: [w, h - 2], port: port || [Math.floor(w / 2), h - 3], draw, leds });

Object.assign(SPRITES, MISC, {
  rack2u:     { ...SPRITES.rack },
  switch1u:   rackUnit('switch', [[3, 3], [5, 3], [7, 3], [9, 3], [11, 3], [13, 3]]),
  router1u:   rackUnit('router', [[12, 4], [12, 6]]),
  wlc1u:      rackUnit('wlc', [[4, 6], [7, 6]]),
  ctl1u:      rackUnit('ctl', [[4, 6], [7, 6]]),
  asa1u:      rackUnit('asa', [[4, 6], [7, 6]]),
  hub:        one(14, 11, hub, [[3, 4], [6, 4], [9, 4], [12, 4]]),
  ap:         one(12, 16, accessPoint, [[6, 4]], [6, 13]),
  modem:      one(10, 13, modem, [[3, 4], [5, 4], [7, 4]]),
  phone:      one(12, 16, phone),
  tablet:     one(12, 16, tablet),
  printer:    one(14, 14, printer, [], [7, 10]),
  tv:         one(16, 16, tv, [], [8, 12]),
  ipphone:    one(13, 12, ipPhone, [[3, 5]], [6, 8]),
  iot_light:  one(11, 16, lamp),
  iot_plug:   one(11, 14, plug, [], [5, 10]),
  iot_thermo: one(11, 16, thermostat),
  iot_camera: one(12, 16, camera, [], [6, 12]),
  iot_sensor: one(11, 16, sensor, [[4, 5]]),
  iot_street: { w: 17, h: 24, off: [0, -8], box: [17, 22], port: [7, 20], draw: streetLight, leds: [] },
  iot_traffic: { w: 12, h: 24, off: [2, -8], box: [12, 22], port: [6, 20], draw: trafficLight, leds: [] },
  iot_plc:    one(14, 17, plc, [[3, 4], [8, 4]], [7, 12]),
});

/* ---------- power and cooling: stand on open floor ---------- */
function deskFan(c) {
  shadow(c, 2, 14, 10);
  R(c, 5, 9, 2, 5, C.metal2); R(c, 2, 13, 8, 1, C.metal3);           // stand and base
  R(c, 1, 0, 10, 10, C.k); R(c, 2, 1, 8, 8, '#c9d6e3');               // cage
  R(c, 5, 2, 2, 6, '#7f93a8'); R(c, 3, 4, 6, 2, '#7f93a8');           // blades
  R(c, 5, 4, 2, 2, C.metal3);
}
function portableAc(c) {
  shadow(c, 1, 15, 12);
  R(c, 0, 0, 12, 15, C.k); R(c, 1, 1, 10, 13, '#e6ecf2'); R(c, 1, 1, 10, 1, '#ffffff');
  for (let y = 3; y < 8; y += 2) R(c, 2, y, 8, 1, '#9fb0c2');         // air louvres
  R(c, 2, 10, 4, 2, '#1c2638'); R(c, 3, 11, 2, 1, C.cyan);             // display
  R(c, 8, 10, 2, 2, '#9fb0c2');
}
function inRow(c) {
  shadow(c, 1, 15, 12);
  R(c, 0, 0, 12, 15, C.k); R(c, 1, 1, 10, 13, '#22303f');
  for (let y = 2; y < 13; y += 2) R(c, 2, y, 8, 1, '#3b5068');         // perforated door
  R(c, 2, 1, 8, 1, C.cyan);
}
function crac(c) {
  shadow(c, 0, 15, 14);
  R(c, 0, 0, 14, 15, C.k); R(c, 1, 1, 12, 13, '#d7dee6'); R(c, 1, 1, 12, 1, '#f4f7fa');
  R(c, 2, 3, 10, 5, '#5d6c7e'); R(c, 3, 4, 8, 3, '#2a3446');           // fan grille
  R(c, 6, 4, 2, 3, '#7f93a8'); R(c, 4, 5, 6, 1, '#7f93a8');
  R(c, 2, 10, 5, 2, '#1c2638'); R(c, 3, 11, 3, 1, C.cyan); R(c, 9, 10, 3, 3, '#5d6c7e');
}
function generator(c, body, hi, big) {
  shadow(c, 0, 14, 16);
  R(c, 0, 3, 16, 11, C.k); R(c, 1, 4, 14, 9, body); R(c, 1, 4, 14, 1, hi);
  for (let x = 3; x < 13; x += 2) R(c, x, 6, 1, 4, 'rgba(0,0,0,.35)'); // vents
  R(c, 12, 0, 2, 4, C.metal2);                                         // exhaust
  if (big) { R(c, 2, 11, 4, 1, C.amber); R(c, 10, 11, 3, 1, C.lime); }
  else R(c, 2, 11, 3, 1, C.lime);
}
function upgradeIcon(c) { // a wrench over a bolt: an efficiency upgrade for a place
  R(c, 2, 2, 12, 12, C.k); R(c, 3, 3, 10, 10, '#1c2a1f');
  R(c, 8, 4, 2, 3, C.lime); R(c, 6, 7, 4, 1, C.lime); R(c, 6, 8, 2, 3, C.lime);
}
Object.assign(SPRITES, {
  deskfan:    one(12, 16, deskFan),
  portac:     one(12, 16, portableAc, [[3, 11]]),
  inrow:      one(12, 16, inRow, [[3, 1], [7, 1]]),
  crac:       one(14, 16, crac, [[4, 11]]),
  genport:    one(16, 15, (c) => generator(c, '#c8341f', '#e8553e', false), [[3, 11]]),
  gendiesel:  one(16, 15, (c) => generator(c, '#d9a03a', '#f0c060', true), [[3, 11]]),
  genturbine: one(16, 15, (c) => generator(c, '#5d6c7e', '#8a9bb0', true), [[3, 11]]),
  upgrade:    one(16, 16, upgradeIcon),
});
