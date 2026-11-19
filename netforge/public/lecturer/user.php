<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Accounts;
use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;
use NetForge\GameError;

// FR-1.5: one account. Edit details and role, switch it off or on, make a reset link.
$me = Staff::beginAdmin();
$id = Staff::int($_GET['id'] ?? '', 1, PHP_INT_MAX, 0);
$u = Db::one('SELECT id, username, email, display_name, avatar, role, active, level, xp, credits, created_at, last_login_at FROM users WHERE id = ?', [$id]);
if ($u === null) {
    Staff::flash('error', 'That account doesn’t exist.');
    Staff::redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'save') {
            Accounts::update((int) $me['id'], $id, (string) ($_POST['display_name'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['role'] ?? ''));
            Staff::flash('ok', 'Saved.');
        } elseif ($action === 'deactivate' || $action === 'activate') {
            Accounts::setActive((int) $me['id'], $id, $action === 'activate');
            Staff::flash('ok', $action === 'activate' ? 'Account switched back on. They can sign in again.' : 'Account switched off. They’re signed out and can’t sign in. Nothing was deleted.');
        } elseif ($action === 'reset') {
            if (!$u['active']) {
                throw new GameError('Switch the account back on before making a reset link.');
            }
            $link = Accounts::createResetLink($id, (int) $me['id']);
            Accounts::log((int) $me['id'], $id, 'Made a password reset link');
            $_SESSION['reset_link'] = $link + ['for' => $id];
            Staff::flash('ok', 'Reset link made. Send it to them yourself; it works once.');
        }
    } catch (GameError $e) {
        Staff::flash('error', $e->getMessage());
    }
    Staff::back();
}
$link = ($_SESSION['reset_link']['for'] ?? null) === $id ? $_SESSION['reset_link'] : null;
unset($_SESSION['reset_link']);
$history = Accounts::history($id);
$self = (int) $me['id'] === $id;

$title = Accounts::name($u);
$active = 'users';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<p class="crumbs"><a href="users.php">Users</a></p>
<div class="page-head">
  <div class="who-head"><canvas data-avatar="<?= e($u['avatar']) ?>" data-scale="3" aria-hidden="true"></canvas>
    <div><h1><?= e(Accounts::name($u)) ?></h1>
    <p class="lede"><span class="mono"><?= e($u['username']) ?></span>, <?= e($u['role']) ?>, joined <?= date('j M Y', strtotime($u['created_at'])) ?>.
      Last signed in <?= $u['last_login_at'] ? date('j M Y, H:i', strtotime($u['last_login_at'])) : 'never' ?>.
      <?php if (!$u['active']): ?><span class="verdict bad"><i class="ti ti-ban" aria-hidden="true"></i>Switched off</span><?php endif; ?></p></div>
  </div>
  <?php if ($u['role'] === 'student'): ?><a class="btn" href="student.php?id=<?= $id ?>"><i class="ti ti-chart-line" aria-hidden="true"></i>Progress report</a><?php endif; ?>
</div>

<?php if ($link): ?>
<section class="panel reset-link">
  <h2>Password reset link</h2>
  <p class="help">Works once, for <?= (int) $link['minutes'] ?> minutes. Making another link cancels this one.</p>
  <div class="copy-row"><input class="mono" id="reset-url" value="<?= e($link['url']) ?>" readonly aria-label="Reset link"><button type="button" class="btn" data-copy="reset-url"><i class="ti ti-copy" aria-hidden="true"></i>Copy</button></div>
</section>
<?php endif; ?>

<div class="two-col">
  <section class="panel">
    <h2>Details and role</h2>
    <form method="post" class="form">
      <?= Csrf::field() ?><input type="hidden" name="action" value="save">
      <label>Display name <small>Optional. Shown instead of the username.</small><input name="display_name" value="<?= e($u['display_name']) ?>" maxlength="40"></label>
      <label>Email <input type="email" name="email" value="<?= e($u['email']) ?>"></label>
      <label>Role <select name="role" <?= $self ? 'disabled' : '' ?>>
        <?php foreach (Accounts::ROLES as $r): ?><option value="<?= $r ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select>
        <?php if ($self): ?><input type="hidden" name="role" value="<?= e($u['role']) ?>"><small>You can’t change your own role.</small><?php endif; ?></label>
      <p class="help">Students play the game. Lecturers manage content and see analytics. Admins can also manage accounts and attack settings. A change applies on their next click.</p>
      <div class="actions"><button class="btn primary">Save</button></div>
    </form>
  </section>

  <section class="panel">
    <h2>Access</h2>
    <form method="post" class="form">
      <?= Csrf::field() ?>
      <div>
        <strong>Password</strong>
        <p class="help">Make a one-time link to send them. Admins never see or set anyone’s password here.</p>
        <div class="actions start"><button class="btn" name="action" value="reset" <?= $u['active'] ? '' : 'disabled' ?>><i class="ti ti-key" aria-hidden="true"></i>Make reset link</button></div>
      </div>
      <div>
        <strong><?= $u['active'] ? 'Switch off' : 'Switch back on' ?></strong>
        <p class="help"><?= $u['active'] ? 'They’re signed out straight away and can’t sign in. Their progress, guild and history are kept.' : 'They can sign in again and carry on where they left off.' ?></p>
        <div class="actions start">
          <?php if ($u['active']): ?><button class="btn danger" name="action" value="deactivate" <?= $self ? 'disabled title="You can’t switch off your own account."' : '' ?>><i class="ti ti-ban" aria-hidden="true"></i>Deactivate account</button>
          <?php else: ?><button class="btn" name="action" value="activate"><i class="ti ti-circle-check" aria-hidden="true"></i>Reactivate account</button><?php endif; ?>
        </div>
      </div>
    </form>
  </section>
</div>

<section class="panel">
  <h2>Admin changes</h2>
  <?php if (!$history): ?><p class="empty">No admin has changed this account.</p><?php else: ?>
  <table class="data">
    <thead><tr><th scope="col">When</th><th scope="col">Admin</th><th scope="col">Change</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?>
      <tr><td class="mono nowrap"><?= date('j M Y H:i', strtotime($h['created_at'])) ?></td><td><?= e($h['admin']) ?></td><td><?= e($h['action']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
</section>
<script type="module">
  import { paintAvatars } from '../assets/js/game/art.js';
  paintAvatars();
  document.querySelector('[data-copy]')?.addEventListener('click', async (e) => {
    const input = document.getElementById(e.currentTarget.dataset.copy);
    input.select();
    try { await navigator.clipboard.writeText(input.value); e.currentTarget.lastChild.textContent = 'Copied'; } catch { /* selected; Ctrl+C works */ }
  });
</script>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
