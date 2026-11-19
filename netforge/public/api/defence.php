<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Defence\Defence;
use NetForge\GameError;

// GET              -> {attack: the attack in progress or null, security: rating}
//                     (starts a new attack when one is due; never during a lesson or exam)
// GET ?history=1   -> {history: past attacks with the review, security}
// POST {attack, choice} -> the defence result
$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$uid = Api::begin($method);
Api::run(function () use ($uid, $method): void {
    if ($method === 'POST') {
        $in = Api::body();
        if (!is_int($in['attack'] ?? null) || !is_int($in['choice'] ?? null)) {
            throw new GameError('Pick a response.');
        }
        Api::ok(['result' => Defence::defend($uid, $in['attack'], $in['choice'])]);
    }
    if (isset($_GET['history'])) {
        Defence::resolveExpired($uid);
        Api::ok(['history' => Defence::history($uid), 'security' => Defence::rating($uid)]);
    }
    // A lesson or exam counts as in progress only if a question was served recently,
    // so a run abandoned by closing the tab doesn't block attacks forever.
    $run = $_SESSION['nf_run'] ?? null;
    $runActive = $run && ($run['served']['at'] ?? 0) > time() - 600;
    // Only the game itself may start a new attack: it sends the CSRF header on every call.
    // A link from another site can't, so it can't set off an attack while the student is away.
    $fromGame = \NetForge\Csrf::check();
    Api::ok(['attack' => Defence::poll($uid, $runActive || !$fromGame), 'security' => Defence::rating($uid)]);
});
