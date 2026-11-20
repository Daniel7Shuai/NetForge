<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Ending;
use NetForge\GameError;
use NetForge\GameState;

// POST {action: "rewind"} -> back to just before buying DataTech (the credits come back): {state}
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    if ((Api::body()['action'] ?? '') !== 'rewind') {
        throw new GameError('Unknown action.');
    }
    Ending::rewind($uid);
    Api::ok(['state' => GameState::load($uid)]);
});
