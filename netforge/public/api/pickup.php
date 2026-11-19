<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {device: id} -> puts a placed device back in the inventory (no cables attached).
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $id = Api::body()['device'] ?? null;
    if (!is_int($id)) {
        throw new GameError('Pick a device to pick up.');
    }
    \NetForge\Progress\Income::accrue($uid);
    Rules::pickUp($uid, $id);
    Api::ok(['state' => GameState::load($uid)]);
});
