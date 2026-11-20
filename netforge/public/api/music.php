<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Auth;
use NetForge\Story;

// GET       -> {tracks: [...]} the music that's switched on, in play order
// GET ?id=N -> the uploaded audio file itself (supports byte ranges, so players can seek)
if (!isset($_GET['id'])) {
    $uid = Api::begin('GET');
    Api::run(fn () => Api::ok(['tracks' => Story::tracks()]));
}
if (Auth::current() === null) {
    http_response_code(401);
    exit;
}
$t = Story::trackFile((int) $_GET['id']);
if ($t === null) {
    http_response_code(404);
    exit;
}
session_write_close(); // don't hold the session lock while streaming
$size = filesize($t['path']);
$start = 0;
$end = $size - 1;
header('Content-Type: ' . $t['mime']);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=86400');
if (preg_match('/^bytes=(\d*)-(\d*)$/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $m)) {
    if ($m[1] === '' && $m[2] !== '') {
        $start = max(0, $size - (int) $m[2]); // last N bytes
    } else {
        $start = (int) $m[1];
        $end = $m[2] === '' ? $end : min($end, (int) $m[2]);
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}
header('Content-Length: ' . ($end - $start + 1));
$fh = fopen($t['path'], 'rb');
fseek($fh, $start);
$left = $end - $start + 1;
while ($left > 0 && !feof($fh)) {
    $chunk = fread($fh, min(65536, $left));
    echo $chunk;
    $left -= strlen($chunk);
}
fclose($fh);
