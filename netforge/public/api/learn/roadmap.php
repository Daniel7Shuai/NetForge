<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Learning\Learning;

// GET -> worlds 0-8 with status, Elo rating, tier, levels and exam info,
// plus the Daily Security Audit overview and the mock exam options and history.
$uid = Api::begin('GET');
Api::run(function () use ($uid): void {
    $worlds = Learning::roadmap($uid);
    Api::ok(['worlds' => $worlds, 'audit' => Learning::audit($uid), 'mock' => \NetForge\Learning\Mock::overview($uid, $worlds)]);
});
