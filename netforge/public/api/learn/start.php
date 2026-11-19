<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\Learning\Challenge;

// POST {level: id} starts a practice run; POST {exam: worldId} starts a timed world exam;
// POST {audit: true} starts today's Daily Security Audit;
// POST {mock: {worlds: [worldId...], length: 10|20|30}} starts a timed mock exam (FR-3.5).
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
    if (is_array($in['mock'] ?? null)) {
        $m = $in['mock'];
        $worlds = array_values(array_filter((array) ($m['worlds'] ?? []), 'is_int'));
        Api::ok(Challenge::startMock($uid, $worlds, is_int($m['length'] ?? null) ? $m['length'] : 0));
    }
    if (($in['audit'] ?? false) === true) {
        Api::ok(Challenge::startAudit($uid));
    }
    throw new GameError('Pick a level or an exam to start.');
});
