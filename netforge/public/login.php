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
    if (!Csrf::check()) {
        $error = 'The form expired. Try again.';
    } elseif ($home = Auth::attempt($username, (string) ($_POST['password'] ?? ''))) {
        header('Location: ' . $home);
        exit;
    } else {
        $error = 'That username and password don’t match.';
    }
}

$title = 'Sign in, NetForge';
$styles = ['auth'];
require __DIR__ . '/../src/views/head.php';
?>
<body class="grid-floor">
<main class="terminal">
  <header><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</header>
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <p class="prompt"><b>nf@noc:~$</b> sign in to start your shift</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <label>Username
      <input type="text" name="username" value="<?= e($username) ?>" autocomplete="username" required autofocus>
    </label>
    <label>Password
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button class="primary" type="submit">Sign in</button>
  </form>
  <p class="alt">New here? <a href="register.php">Create an account</a></p>
</main>
</body>
</html>
