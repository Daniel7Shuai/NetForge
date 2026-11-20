<?php
declare(strict_types=1);

// Adds music files from your computer to the game's play list, with the same checks as the
// staff Music page (real audio, under 30 MB, a source and licence note). Files are copied into
// storage/music, which is never committed, so licensed tracks don't end up in the git repo.
//
//   php tools/import-music.php <file> "<title>" "<artist>" "<source and licence>"
//   php tools/import-music.php --list <list.txt>
//
// A list file has one track per line, separated by | :  file | title | artist | licence
// (paths relative to the list file; lines starting with # are skipped).

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Db;
use NetForge\GameError;
use NetForge\Story;

$tracks = [];
if (($argv[1] ?? '') === '--list') {
    $list = $argv[2] ?? '';
    if (!is_file($list)) {
        fwrite(STDERR, "No list file at '$list'.\n");
        exit(1);
    }
    foreach (file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(ltrim($line), '#')) {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) !== 4) {
            fwrite(STDERR, "Skipped (needs file | title | artist | licence): $line\n");
            continue;
        }
        $parts[0] = dirname(realpath($list)) . DIRECTORY_SEPARATOR . $parts[0];
        $tracks[] = $parts;
    }
} elseif (count($argv) === 5) {
    $tracks[] = array_slice($argv, 1);
} else {
    fwrite(STDERR, "Usage: php tools/import-music.php <file> \"<title>\" \"<artist>\" \"<source and licence>\"\n"
        . "       php tools/import-music.php --list <list.txt>\n");
    exit(1);
}

$failed = 0;
foreach ($tracks as [$file, $title, $artist, $licence]) {
    if (Db::one('SELECT 1 FROM music_tracks WHERE title = ? AND artist = ?', [$title, $artist])) {
        echo "Already in the play list: $title ($artist)\n";
        continue;
    }
    if (!is_file($file) && preg_match('/(\d{4,})\.\w+$/', $file, $m)) {
        // downloaded under a slightly different name: find it by the track's number
        $found = glob(dirname($file) . DIRECTORY_SEPARATOR . "*{$m[1]}*.*") ?: [];
        $file = $found[0] ?? $file;
    }
    try {
        $id = Story::addTrackFromFile($file, $title, $artist, $licence);
        echo "Added #$id: $title ($artist)\n";
    } catch (GameError $e) {
        $failed++;
        fwrite(STDERR, basename($file) . ': ' . $e->getMessage() . "\n");
    }
}
exit($failed ? 1 : 0);
