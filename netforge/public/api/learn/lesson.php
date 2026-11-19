<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Learning\Learning;

// GET ?id=<level id> -> the lesson text for a level in an open world.
$uid = Api::begin('GET');
Api::run(fn () => Api::ok(['lesson' => Learning::lesson($uid, (int) ($_GET['id'] ?? 0))]));
