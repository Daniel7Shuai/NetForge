<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Learning\Learning;

// GET -> worlds 0-8 with status, Elo rating, tier, levels and exam info,
// plus the Daily Security Audit overview.
$uid = Api::begin('GET');
Api::run(fn () => Api::ok(['worlds' => Learning::roadmap($uid), 'audit' => Learning::audit($uid)]));
