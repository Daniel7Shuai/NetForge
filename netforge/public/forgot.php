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
$devFile = null;
$who = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $who = (string) ($_POST['who'] ?? '');
    if (!Csrf::check()) {
        $error = 'The form expired. Try again.';
    } else {
        try {
            $where = Accounts::requestReset($who);
            $sent = true;
            // in the default 'log' transport the email is saved on disk; say where, so a demo can open it
            if ($where !== null && $where !== 'sent' && Config::get('app.debug')) {
                $devFile = str_replace('\\', '/', substr($where, strlen(NF_ROOT) + 1));
            }
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
    <p class="prompt"><b>nf@noc:~$</b> reset a forgotten password</p>
    <?php if ($error): ?><p class="error" role="alert"><?= e($error) ?></p><?php endif; ?>
    <?php if ($sent): ?>
      <p class="note" role="status">If that account exists and has an email, a reset link is on its way. It works once, for <?= (int) \NetForge\Db::configInt('reset_minutes', 30) ?> minutes. Check your spam folder too.</p>
      <?php if ($devFile): ?><p class="note info">Development mode: emails aren’t sent. This one was saved to <code><?= e($devFile) ?></code>.</p><?php endif; ?>
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
