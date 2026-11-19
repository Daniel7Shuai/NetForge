# NetForge

Inferred from the client's brief (2026-11-19); items marked *assumed* were not stated.

## What it is
A browser game (PHP + MySQL backend, Phaser.js map) where players build working networks on a top-down 2D grid: place PCs, switches, routers and server racks, then run cables port to port like Cisco Packet Tracer, with every link visible on the map.

## Who plays
Students and early networking learners who want hands-on cabling practice that feels like a game, not a simulator. *Assumed:* school-age to early-university, mostly on laptops.

## Primary job
After login, drop the player straight into the map. Gameplay is the product; menus stay out of the way.

## Surfaces
- Login / register (Persuade-lite: one panel)
- Play (Operate + Experience): map, tool rail, objective, money, XP, inventory, shop, inspector, console

## Visual direction (pinned by client)
Cybercore ESC: dark background, neon accents, terminal-style text, clean uncluttered panels. Map is detailed pixel art (character, desks with PCs, server racks), not abstract shapes.

## Tokens
- Colors: void #060A12, panel #0D1522, grid #17233A, ink #C9E4F2, ink-dim #6B86A0, cyan #2BE3FF (interactive, copper), amber #FFB43D (money, fiber, negotiating), lime #7CFF6B (link up, done), magenta #FF3D9A (errors, link down), violet #9C8CFF (console cable)
- Type: Chakra Petch (UI, headings), JetBrains Mono (console, ports, numbers)
