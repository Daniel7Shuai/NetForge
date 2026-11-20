-- NetForge: five cozy jazz-piano tracks, composed in code like the chiptune ones (music.js),
-- so there's no licence to worry about. Run after schema_datatech.sql. Safe to run again.
USE netforge;

INSERT INTO music_tracks (kind, synth_key, title, artist, licence, sort_order) VALUES
 ('synth', 'coffee-break',      'Coffee Break',      'NetForge', 'Original, composed for NetForge', 60),
 ('synth', 'rainy-router',      'Rainy Router',      'NetForge', 'Original, composed for NetForge', 70),
 ('synth', 'late-night-lab',    'Late Night Lab',    'NetForge', 'Original, composed for NetForge', 80),
 ('synth', 'patch-panel-blues', 'Patch Panel Blues', 'NetForge', 'Original, composed for NetForge', 90),
 ('synth', 'uptime-bossa',      'Uptime Bossa',      'NetForge', 'Original, composed for NetForge', 100)
ON DUPLICATE KEY UPDATE synth_key = synth_key;
