<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {cable, a:{device, port}, b:{device, port}} -> runs a cable port to port.
// The link comes up only with the right cable type for the two ports.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $a = $in['a'] ?? null;
    $b = $in['b'] ?? null;
    $ok = is_string($in['cable'] ?? null) && is_array($a) && is_array($b)
        && is_int($a['device'] ?? null) && is_string($a['port'] ?? null)
        && is_int($b['device'] ?? null) && is_string($b['port'] ?? null);
    if (!$ok) {
        throw new GameError('Pick a cable, then a port on each device.');
    }
    \NetForge\Progress\Income::accrue($uid); // settle income at the old rate before the network changes
    [$status, $message] = Rules::connect($uid, $in['cable'], $a['device'], $a['port'], $b['device'], $b['port']);
    Rules::checkObjective($uid);
    Api::ok(['status' => $status, 'message' => $message, 'state' => GameState::load($uid)]);
});
