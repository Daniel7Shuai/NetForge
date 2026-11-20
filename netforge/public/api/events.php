<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Events;
use NetForge\GameError;

// GET ?start=1   -> {event, unread, buffs}: polled by the game (may start a due event)
// GET ?phone=1   -> {messages, history, value, eligible}
// POST {action: "broker", event, choice: "accept"|"refuse"} -> {result, poll}
// POST {action: "investor", event, choice: "accept"|"more"|"decline"} -> {result, poll, phone}
// POST {action: "read"} -> marks texts read
$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$uid = Api::begin($method);
Api::run(function () use ($uid, $method): void {
    if ($method === 'GET') {
        if (isset($_GET['phone'])) {
            Api::ok(Events::phone($uid));
        }
        Api::ok(Events::poll($uid, ($_GET['start'] ?? '') === '1'));
    }
    $in = Api::body();
    switch ($in['action'] ?? null) {
        case 'broker':
            if (!is_int($in['event'] ?? null)) {
                throw new GameError('Which broker?');
            }
            $result = Events::answerBroker($uid, $in['event'], ($in['choice'] ?? '') === 'accept' ? 'accept' : 'refuse');
            Api::ok(['result' => $result, 'poll' => Events::poll($uid, false)]);
        case 'investor':
            if (!is_int($in['event'] ?? null)) {
                throw new GameError('Which offer?');
            }
            $choice = in_array($in['choice'] ?? '', ['accept', 'more', 'decline'], true) ? $in['choice'] : 'decline';
            $result = Events::answerInvestor($uid, $in['event'], $choice);
            Api::ok(['result' => $result, 'poll' => Events::poll($uid, false), 'phone' => Events::phone($uid)]);
        case 'read':
            Events::markRead($uid);
            Api::ok(['unread' => 0]);
        default:
            throw new GameError('Unknown event action.');
    }
});
