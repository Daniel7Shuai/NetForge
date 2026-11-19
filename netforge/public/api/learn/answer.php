<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameState;
use NetForge\Learning\Challenge;

// POST {answer} -> marks the current question.
// Replies with feedback, then either the next question or the run summary
// (with the fresh game state, since credits, XP, unlocks and objectives may have changed).
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $out = Challenge::answer($uid, Api::body()['answer'] ?? null);
    Api::ok($out + ['state' => GameState::load($uid)]);
});
