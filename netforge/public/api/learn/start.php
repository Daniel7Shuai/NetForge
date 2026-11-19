<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\Learning\Challenge;

// POST {level: id} starts a practice run; POST {exam: worldId} starts a timed world exam;
// POST {audit: true} starts today's Daily Security Audit.
// Replies with the run header and the first question (no answer key).
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    if (is_int($in['level'] ?? null)) {
        Api::ok(Challenge::startPractice($uid, $in['level']));
    }
    if (is_int($in['exam'] ?? null)) {
        Api::ok(Challenge::startExam($uid, $in['exam']));
    }
    if (($in['audit'] ?? false) === true) {
        Api::ok(Challenge::startAudit($uid));
    }
    throw new GameError('Pick a level or an exam to start.');
});
