<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\Story;

// GET  -> {characters: [{code, name, role, side, art}], tutorial: {step, done}}
// POST {action: "step", step} | {action: "done"} | {action: "restart"} -> {tutorial}
$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$uid = Api::begin($method);
Api::run(function () use ($uid, $method): void {
    if ($method === 'GET') {
        Api::ok(['characters' => Story::characters(), 'tutorial' => Story::tutorial($uid)]);
    }
    $in = Api::body();
    $action = $in['action'] ?? null;
    if (!is_string($action)) {
        throw new GameError('Missing tutorial action.');
    }
    Api::ok(['tutorial' => Story::setTutorial($uid, $action, is_int($in['step'] ?? null) ? $in['step'] : 0)]);
});
