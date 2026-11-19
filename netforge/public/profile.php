<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use NetForge\Accounts;
use NetForge\Auth;
use NetForge\Csrf;
use NetForge\GameError;

// FR-1.4: every user edits their display name, avatar, email and password.
$uid = Auth::requirePage();
$errors = ['profile' => null, 'password' => null];
$saved = $_SESSION['profile_saved'] ?? null;
unset($_SESSION['profile_saved']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $which = ($_POST['action'] ?? '') === 'password' ? 'password' : 'profile';
    if (!Csrf::check()) {
        $errors[$which] = 'The form expired. Try again.';
    } else {
        try {
            if ($which === 'profile') {
                Accounts::updateProfile($uid, (string) ($_POST['display_name'] ?? ''), (string) ($_POST['avatar'] ?? ''), (string) ($_POST['email'] ?? ''));
                $_SESSION['profile_saved'] = 'Profile saved.';
            } else {
                Accounts::changePassword($uid, (string) ($_POST['current'] ?? ''), (string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
                $_SESSION['profile_saved'] = 'Password changed. Use the new one next time you sign in.';
            }
            header('Location: profile.php');
            exit;
        } catch (GameError $e) {
            $errors[$which] = $e->getMessage();
        }
    }
}

$me = Accounts::profile($uid);
// keep what they typed if the save failed
$display = $errors['profile'] ? (string) ($_POST['display_name'] ?? '') : (string) $me['display_name'];
$email = $errors['profile'] ? (string) ($_POST['email'] ?? '') : (string) $me['email'];
$avatar = $errors['profile'] ? (string) ($_POST['avatar'] ?? $me['avatar']) : $me['avatar'];
$back = Auth::home($me['role']);

$title = 'Profile, NetForge';
$styles = ['auth', 'profile'];
require __DIR__ . '/../src/views/head.php';
?>
<body class="grid-floor">
<main class="terminal wide">
  <header><i class="ti ti-topology-star-3" aria-hidden="true"></i>NetForge
    <a class="back" href="<?= e($back) ?>"><i class="ti ti-arrow-left" aria-hidden="true"></i><?= $me['role'] === 'student' ? 'Back to the game' : 'Back to lecturer pages' ?></a></header>

  <div class="me">
    <canvas id="me-avatar" data-avatar="<?= e($avatar) ?>" data-scale="4" aria-hidden="true"></canvas>
    <div><h1 id="me-name"><?= e(Accounts::name($me)) ?></h1>
      <p class="hint"><span class="mono"><?= e($me['username']) ?></span>, <?= e($me['role']) ?>, joined <?= date('j M Y', strtotime($me['created_at'])) ?>.</p></div>
  </div>
  <?php if ($saved): ?><p class="note saved" role="status"><?= e($saved) ?></p><?php endif; ?>

  <form method="post" novalidate class="section">
    <?= Csrf::field() ?><input type="hidden" name="action" value="profile">
    <h2>Profile</h2>
    <?php if ($errors['profile']): ?><p class="error" role="alert"><?= e($errors['profile']) ?></p><?php endif; ?>
    <fieldset class="avatars">
      <legend>Avatar <span class="hint">Your character on the map</span></legend>
      <?php foreach (Accounts::AVATARS as $key => $label): ?>
        <label class="av"><input type="radio" name="avatar" value="<?= e($key) ?>" <?= $avatar === $key ? 'checked' : '' ?>>
          <canvas data-avatar="<?= e($key) ?>" data-scale="2" aria-hidden="true"></canvas><span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>
    <label>Display name <span class="hint">Optional. Shown instead of your username.</span>
      <input type="text" name="display_name" value="<?= e($display) ?>" maxlength="40" placeholder="<?= e($me['username']) ?>">
    </label>
    <label>Email <span class="hint">Where password reset links go.</span>
      <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" required>
    </label>
    <button class="primary" type="submit">Save profile</button>
  </form>

  <form method="post" novalidate class="section">
    <?= Csrf::field() ?><input type="hidden" name="action" value="password">
    <h2>Password</h2>
    <?php if ($errors['password']): ?><p class="error" role="alert"><?= e($errors['password']) ?></p><?php endif; ?>
    <input type="text" name="username" value="<?= e($me['username']) ?>" autocomplete="username" hidden>
    <label>Current password <input type="password" name="current" autocomplete="current-password" required></label>
    <div class="pair">
      <label>New password <input type="password" name="password" autocomplete="new-password" required minlength="8"></label>
      <label>Confirm new password <input type="password" name="confirm" autocomplete="new-password" required></label>
    </div>
    <span class="hint">At least 8 characters.</span>
    <button class="primary" type="submit">Change password</button>
  </form>
</main>
<script type="module">
  import { paintAvatars } from './assets/js/game/art.js';
  paintAvatars();
  // preview the chosen avatar and name before saving
  const big = document.getElementById('me-avatar');
  document.querySelectorAll('input[name=avatar]').forEach((r) => r.addEventListener('change', () => {
    big.dataset.avatar = r.value;
    paintAvatars(big.parentElement);
  }));
  const name = document.getElementById('me-name');
  const input = document.querySelector('input[name=display_name]');
  input.addEventListener('input', () => { name.textContent = input.value.trim() || input.placeholder; });
</script>
</body>
</html>
