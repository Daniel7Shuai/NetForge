<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Progress\Badges;
use NetForge\Progress\Income;

// GET -> every badge (earned or not), the learning streak and the income summary.
$uid = Api::begin('GET');
Api::run(fn () => Api::ok([
    'badges' => Badges::catalog($uid),
    'streak' => Badges::streak($uid),
    'income' => Income::summary($uid),
]));
