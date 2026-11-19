<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Auth;
use NetForge\Csrf;

if ($me = Auth::current()) {
    header('Location: ' . Auth::home($me['role']));
    exit;
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (!Csrf::check()) {
        $error = 'The form expired. Try again.';
    } elseif ($password !== (string) ($_POST['confirm'] ?? '')) {
        $error = 'The two passwords don’t match.';
    } else {
        $error = Auth::register($username, $password);
        if ($error === null) {
            header('Location: play.php');
            exit;
        }
    }
}

$title = 'Create account, NetForge';
$styles = ['auth'];
require __DIR__ . '/../src/views/head.php';
?>
<body class="grid-floor">
<main class="terminal">
  <header><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</header>
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <p class="prompt"><b>nf@noc:~$</b> create an account to get your first desk</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <label>Username
      <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="new-password" required>
    </label>
    <label>Confirm password
      <input type="password" name="confirm" autocomplete="new-password" required>
    </label>
    <button class="primary" type="submit">Create account</button>
  </form>
  <p class="alt">Already have one? <a href="login.php">Sign in</a></p>
</main>
</body>
</html>
