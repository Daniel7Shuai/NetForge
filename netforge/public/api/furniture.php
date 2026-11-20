<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Gear;

// POST {action: "place", item, x, y} | {action: "pickup", id} -> {state, message}
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $action = $in['action'] ?? null;
    if ($action === 'place') {
        if (!is_string($in['item'] ?? null) || !is_int($in['x'] ?? null) || !is_int($in['y'] ?? null)) {
            throw new GameError('Pick some furniture and an empty spot.');
        }
        $msg = Gear::placeFurniture($uid, $in['item'], $in['x'], $in['y']);
    } elseif ($action === 'pickup') {
        if (!is_int($in['id'] ?? null)) {
            throw new GameError('Pick the furniture to pick up.');
        }
        Gear::pickUpFurniture($uid, $in['id']);
        $msg = 'Picked it up.';
    } else {
        throw new GameError('Unknown furniture action.');
    }
    Api::ok(['state' => GameState::load($uid), 'message' => $msg]);
});
