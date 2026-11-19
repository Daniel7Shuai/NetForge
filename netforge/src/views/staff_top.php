<?php
/** @var string $title  @var string $active  @var array $me */
use NetForge\Content\Staff;
use NetForge\Csrf;

$nav = [
    'index'     => ['index.php', 'Content'],
    'questions' => ['questions.php', 'Question bank'],
    'import'    => ['import.php', 'CSV import'],
    'unlocks'   => ['unlocks.php', 'Unlocks and prices'],
    'challenges' => ['challenges.php', 'Weekly challenges'],
    'attacks'   => ['attacks.php', 'Cyber attacks'],
    'analytics' => ['analytics.php', 'Analytics'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?>, NetForge lecturer</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.19.0/dist/tabler-icons.min.css">
<link rel="stylesheet" href="../assets/css/tokens.css">
<link rel="stylesheet" href="../assets/css/lecturer.css">
</head>
<body>
<header class="staff-top">
  <a class="brand" href="index.php"><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge <span>Lecturer</span></a>
  <nav aria-label="Lecturer">
    <?php foreach ($nav as $navKey => [$navHref, $navLabel]): ?>
      <a href="<?= $navHref ?>"<?= $active === $navKey ? ' aria-current="page"' : '' ?>><?= e($navLabel) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="who">
    <a href="../play.php" title="Open the student game"><i class="ti ti-device-gamepad-2" aria-hidden="true"></i>Game</a>
    <span class="mono"><?= e($me['username']) ?> · <?= e($me['role']) ?></span>
    <form method="post" action="../logout.php"><?= Csrf::field() ?><button type="submit">Sign out</button></form>
  </div>
</header>
<main class="staff-main">
<?php foreach (Staff::takeFlash() as [$kind, $msg]): ?>
  <p class="flash <?= e($kind) ?>" role="<?= $kind === 'error' ? 'alert' : 'status' ?>"><i class="ti <?= $kind === 'error' ? 'ti-alert-triangle' : 'ti-circle-check' ?>" aria-hidden="true"></i><?= e($msg) ?></p>
<?php endforeach; ?>
