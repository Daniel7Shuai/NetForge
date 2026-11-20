<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Story;

// GET ?c=teacher -> the character's portrait image. Signed-in users only.
// The game adds &v=<last change> to the address, so it can be cached for a long time.
if (Auth::current() === null) {
    http_response_code(401);
    exit;
}
$a = Story::art((string) ($_GET['c'] ?? ''));
if ($a === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $a['art_mime']);
header('Content-Length: ' . strlen($a['art']));
header('Cache-Control: private, max-age=31536000, immutable');
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
echo $a['art'];
