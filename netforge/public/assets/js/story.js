// Shared story data: the characters (names, roles, art) from the server and the tutorial
// script, loaded once. Also fills {player}, {teacher}, {hacker} into lines.
import { api } from './api.js';

let loaded = null;

/** Loads characters, tutorial progress and the script (once). */
export function loadStory() {
  loaded ??= Promise.all([
    api.story(),
    fetch(new URL('../data/tutorial.json', import.meta.url)).then((r) => r.json()),
  ]).then(([s, script]) => ({ characters: s.characters, tutorial: s.tutorial, script }));
  return loaded;
}

export function character(story, code) {
  return story.characters.find((c) => c.code === code) || { code, name: code, role: '', side: 'left', art: null };
}

/** Replaces {player}, {teacher}, {hacker} (any character code) with names. */
export function fill(story, text, playerName) {
  return text.replace(/\{(\w+)\}/g, (m, key) => (key === 'player' ? playerName : story.characters.find((c) => c.code === key)?.name ?? m));
}
