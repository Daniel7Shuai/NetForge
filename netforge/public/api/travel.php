<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\Rules;

// POST {location: code} -> goes to a location, leasing it first if needed.
// The client reloads the page afterwards, since the room's map changes.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $code = Api::body()['location'] ?? null;
    if (!is_string($code)) {
        throw new GameError('Pick a place to go.');
    }
    \NetForge\Progress\Income::accrue($uid);
    Api::ok(['message' => Rules::travel($uid, $code)]);
});