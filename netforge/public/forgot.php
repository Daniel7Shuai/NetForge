<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Accounts;
use NetForge\Config;
use NetForge\Csrf;
use NetForge\GameError;

// FR-1.3, step 1: ask for a one-time reset link by username or email.
$error = null;
$sent = false;
$devNote = false;
$who = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = (string) ($_POST['who'] ?? '');
    if (!Csrf::check()) {
        $error = 'The form expired. Try again.';
    } else {
        try {
            Accounts::requestReset($who);
            $sent = true;
            // In the default 'log' transport emails are saved on disk instead of sent. Say so the same way
            // for every request: naming the file only for real accounts would reveal who has one.
            $devNote = Config::get('mail.transport', 'log') !== 'mail' && (bool) Config::get('app.debug');
        } catch (GameError $e) {
            $error = $e->getMessage();
        }
    }
}

$title = 'Reset password, NetForge';
$styles = ['auth'];
require __DIR__ . '/../src/views/head.php';
?>
<body class="grid-floor">
<main class="terminal">
  <header><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge</header>
  <form method="post" novalidate>
    <?= Csrf::field() ?>
    <p class="prompt">Reset a forgotten password</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($sent): ?>
      <p class="note" role="status">If that account exists and has an email, a reset link is on its way. It works once, for <?= (int) \NetForge\Db::configInt('reset_minutes', 30) ?> minutes. Check your spam folder too.</p>
      <?php if ($devNote): ?><p class="note info">Development mode: emails aren’t sent. If the account exists, its email was saved in <code>storage/mail/</code> on the server.</p><?php endif; ?>
    <?php else: ?>
      <label>Username or email
        <input type="text" name="who" value="<?= e($who) ?>" autocomplete="username" required autofocus>
      </label>
      <button class="primary" type="submit">Send reset link</button>
    <?php endif; ?>
  </form>
  <p class="alt">Remembered it? <a href="login.php">Sign in</a></p>
</main>
</body>
</html>
