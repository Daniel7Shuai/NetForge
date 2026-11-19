<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {device, x, y} -> moves a placed device to another free spot in the same room.
// Its cables stay plugged in; the client re-routes them from the new position.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $id = $in['device'] ?? null;
    $x = $in['x'] ?? null;
    $y = $in['y'] ?? null;
    if (!is_int($id) || !is_int($x) || !is_int($y)) {
        throw new GameError('Pick a device and a spot to move it to.');
    }
    Rules::move($uid, $id, $x, $y);
    Api::ok(['state' => GameState::load($uid)]);
});