<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameState;

// GET -> everything the client needs to draw the game.
$uid = Api::begin('GET');
Api::run(fn () => Api::ok(GameState::load($uid)));
