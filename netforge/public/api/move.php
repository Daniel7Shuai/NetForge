<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;

// POST {device, x, y} -> moves a placed device; its cables re-route.
// TODO(gameplay): validate target slot, update devices, recompute cable routes.
$uid = Api::begin('POST');
Api::fail(501, 'Moving devices is not built yet.');
