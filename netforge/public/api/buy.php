<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {id} -> buys a shop row: checks world mastery and credits, adds to inventory.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $id = Api::body()['id'] ?? null;
    if (!is_int($id)) {
        throw new GameError('Pick something to buy.');
    }
    $name = Rules::buy($uid, $id);
    Rules::checkObjective($uid);
    Api::ok(['bought' => $name, 'state' => GameState::load($uid)]);
});
