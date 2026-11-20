<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Accounts;
use NetForge\Csrf;
use NetForge\GameError;

// FR-1.3, step 2: the one-time link from the email. Choose a new password.
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$user = Accounts::tokenUser($token);
$error = null;
$done = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user !== null) {
    if (!Csrf::check()) {
        $error = 'The form expired. Try again.';
    } else {
        try {
            Accounts::resetPassword($token, (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
            $done = true;
        } catch (GameError $e) {
            $error = $e->getMessage();
        }
    }
}

$title = 'Choose a new password, NetForge';
$styles = ['auth'];
header('Referrer-Policy: no-referrer'); // keep the token out of other sites' logs
require __DIR__ . '/../src/views/head.php';
?>
<body class="grid-floor">
<main class="terminal">
  <header><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</header>
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <?php if ($done): ?>
      <p class="prompt">Password changed</p>
      <p class="note" role="status">Your password is changed. Sign in with the new one.</p>
      <a class="primary button-link" href="login.php">Sign in</a>
    <?php elseif ($user === null): ?>
      <p class="prompt">Link not valid</p>
      <p class="error" role="alert">This link has expired or was already used. Each link works once, for a short time.</p>
      <a class="primary button-link" href="forgot.php">Ask for a new link</a>
    <?php else: ?>
      <p class="prompt">New password for <?= e($user['username']) ?></p>
      <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
      <input type="text" name="username" value="<?= e($user['username']) ?>" autocomplete="username" hidden>
      <label>New password
        <input type="password" name="password" autocomplete="new-password" required autofocus minlength="8">
        <span class="hint">At least 8 characters.</span>
      </label>
      <label>Confirm new password
        <input type="password" name="confirm" autocomplete="new-password" required>
      </label>
      <button class="primary" type="submit">Change password</button>
    <?php endif; ?>
  </form>
  <p class="alt"><a href="login.php">Back to sign in</a></p>
</main>
</body>
</html>
