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

// off: where the sprite sits in its tile; box: visible size; port: where cables plug in
const one = (w, h, draw, leds = [], port) => ({ w, h, off: [Math.floor((16 - w) / 2), 16 - h], box: [w, h - 2], port: port || [Math.floor(w / 2), h - 3], draw, leds });

Object.assign(SPRITES, {
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
