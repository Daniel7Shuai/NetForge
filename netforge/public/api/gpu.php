<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Gear;

// POST {device: id, card: item code} -> installs a GPU card in a server: {state, message}
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    if (!is_int($in['device'] ?? null) || !is_string($in['card'] ?? null)) {
        throw new GameError('Pick a server and a GPU card.');
    }
    $msg = Gear::installCard($uid, $in['device'], $in['card']);
    Api::ok(['state' => GameState::load($uid), 'message' => $msg]);
});
