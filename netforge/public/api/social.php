<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\GameError;
use NetForge\Social\Social;

// GET  -> everything the Social panel shows (guild, raid, leaderboards, friends).
// POST {action, ...} -> create | join | leave | goal | kick | share | friend-add | friend-remove,
//      then replies with the fresh overview.
$method = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'POST' : 'GET';
$uid = Api::begin($method);
Api::run(function () use ($uid, $method): void {
    if ($method === 'POST') {
        $in = Api::body();
        $str = fn (string $k) => is_string($in[$k] ?? null) ? $in[$k] : '';
        $int = fn (string $k) => is_int($in[$k] ?? null) ? $in[$k] : 0;
        match ($in['action'] ?? '') {
            'create'        => Social::create($uid, $str('name'), $str('tag'), $str('motto')),
            'join'          => Social::join($uid, $int('guild')),
            'leave'         => Social::leave($uid),
            'goal'          => Social::setGoal($uid, $str('title'), $int('target')),
            'kick'          => Social::kick($uid, $int('member')),
            'share'         => Social::share($uid, $str('milestone')),
            'friend-add'    => Social::addFriend($uid, $str('username')),
            'friend-remove' => Social::removeFriend($uid, $int('friend')),
            default         => throw new GameError('Unknown action.'),
        };
    }
    Api::ok(['social' => Social::overview($uid)]);
});
