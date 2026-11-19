<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Rules;

// POST {device: id} -> upgrades a placed device to its next tier (credits, gated by level).
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $id = Api::body()['device'] ?? null;
    if (!is_int($id)) {
        throw new GameError('Pick a device to upgrade.');
    }
    \NetForge\Progress\Income::accrue($uid); // pay out at the old rate first
    $msg = Rules::upgrade($uid, $id);
    Api::ok(['message' => $msg, 'state' => GameState::load($uid)]);
});