<?php
declare(strict_types=1);
require __DIR__ . '/../../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Learning\Challenge;

// POST -> abandons the current run. Nothing is recorded for unanswered questions.
Api::begin('POST');
Challenge::quit();
Api::ok();
