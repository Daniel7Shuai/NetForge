<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Api;
use NetForge\Cli;
use NetForge\GameError;
use NetForge\GameState;
use NetForge\Progress\Income;

// The Work Laptop's console terminal. Either end of the console cable can be given as {device}.
// POST {action: "open", device}                      -> {device: {id, label, name, switch}, prompt, checklist}
// POST {action: "run", device, line, mode, iface}    -> {output, mode, iface, prompt, checklist, configured, state?}
//   (state comes back when the config was saved, since a configured device boosts more)
$uid = Api::begin('POST');
Api::run(function () use ($uid): void {
    $in = Api::body();
    $link = is_int($in['device'] ?? null) ? Cli::link($uid, $in['device']) : null;
    if ($link === null) {
        throw new GameError('Run a console cable from a Work Laptop’s console port to the router’s or switch’s console port (Con0) first.');
    }
    [, $dev] = $link;
    if (($in['action'] ?? '') === 'open') {
        $cfg = Cli::load($dev['config'], $dev);
        Api::ok([
            'device' => ['id' => (int) $dev['id'], 'label' => $dev['label'], 'name' => $dev['name'], 'switch' => $dev['sprite'] === 'switch1u'],
            'prompt' => Cli::prompt($cfg, 'user', null),
            'checklist' => Cli::checklist($dev['config'], $dev),
        ]);
        return;
    }
    $line = is_string($in['line'] ?? null) ? mb_substr($in['line'], 0, 200) : '';
    $mode = is_string($in['mode'] ?? null) ? $in['mode'] : 'user';
    $iface = is_string($in['iface'] ?? null) ? $in['iface'] : null;
    $before = Cli::configured($dev['config'], $dev);
    if ($before === false) {
        Income::accrue($uid); // a save may make it configured: settle income at the old rate first
    }
    [$output, $mode, $iface, $prompt, $saved] = Cli::run($uid, (int) $dev['id'], $line, $mode, $iface);
    [, $dev] = Cli::link($uid, (int) $dev['id']);
    $configured = Cli::configured($dev['config'], $dev);
    Api::ok([
        'output' => $output, 'mode' => $mode, 'iface' => $iface, 'prompt' => $prompt,
        'checklist' => Cli::checklist($dev['config'], $dev), 'configured' => $configured,
    ] + ($saved || $configured !== $before ? ['state' => GameState::load($uid)] : []));
});
