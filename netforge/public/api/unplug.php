<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {cable: id} -> pulls a cable out; it goes back to the inventory.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $id = Api::body()['cable'] ?? null;
    if (!is_int($id)) {
        throw new GameError('Pick a cable to unplug.');
    }
    \NetForge\Progress\Income::accrue($uid); // settle income at the old rate before the network changes
    Rules::unplug($uid, $id);
    Api::ok(['state' => GameState::load($uid)]);
});
