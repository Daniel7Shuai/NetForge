<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Progress\Income;
use NetForge\Staff;

// POST {action: "hire", id}   -> hires the candidate with that resume (office or datacentre only)
// POST {action: "reject", id} -> bins the resume; a new one arrives
// POST {action: "fire", id}   -> lets a member of staff go
// All reply {message?, state}.
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $id = is_int($in['id'] ?? null) ? $in['id'] : 0;
    Income::accrue($uid); // pay out at the old rate before the team changes
    $message = null;
    switch ($in['action'] ?? '') {
        case 'hire':
            $message = Staff::hire($uid, $id);
            break;
        case 'reject':
            Staff::reject($uid, $id);
            break;
        case 'fire':
            Staff::fire($uid, $id);
            break;
        default:
            throw new GameError('Unknown staff action.');
    }
    Api::ok(['message' => $message, 'state' => GameState::load($uid)]);
});
