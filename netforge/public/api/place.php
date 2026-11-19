<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {item, x, y} -> places one inventory device on the slot anchored at (x, y).
// Replies with the new label, any objective it completed, and the fresh game state.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $item = $in['item'] ?? null;
    $x = $in['x'] ?? null;
    $y = $in['y'] ?? null;
    if (!is_string($item) || !is_int($x) || !is_int($y)) {
        throw new GameError('Pick an item and a spot on the map.');
    }

    $label = Rules::place($uid, $item, $x, $y);
    $done = Rules::checkObjective($uid);

    Api::ok([
        'placed'        => $label,
        'objectiveDone' => $done === null ? null : ['title' => $done['title'], 'reward' => $done['reward']],
        'state'         => GameState::load($uid),
    ]);
});
