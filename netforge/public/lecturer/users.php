<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';

use NetForge\Accounts;
use NetForge\Content\Staff;
use NetForge\Csrf;
use NetForge\Db;
use NetForge\GameError;

// FR-1.5: administrators find, create, edit, deactivate and assign roles to accounts.
$me = Staff::beginAdmin();

$form = ['username' => '', 'email' => '', 'role' => 'student'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $form = ['username' => trim((string) ($_POST['username'] ?? '')), 'email' => trim((string) ($_POST['email'] ?? '')), 'role' => (string) ($_POST['role'] ?? 'student')];
    try {
        $id = Accounts::create((int) $me['id'], $form['username'], $form['email'], $form['role'], (string) ($_POST['password'] ?? ''));
        Staff::flash('ok', sprintf('Created %s as %s. Give them the temporary password, or send a reset link from their page.', $form['username'], $form['role']));
        Staff::redirect('user.php?id=' . $id);
    } catch (GameError $e) {
        Staff::flash('error', $e->getMessage());
        $_SESSION['user_form'] = $form;
        Staff::back();
    }
}
$form = $_SESSION['user_form'] ?? $form;
unset($_SESSION['user_form']);

$q = trim((string) ($_GET['q'] ?? ''));
$role = (string) ($_GET['role'] ?? '');
$status = (string) ($_GET['status'] ?? '');
$users = Accounts::search($q, $role, $status);
$counts = [];
foreach (Db::all('SELECT role, SUM(active) active, COUNT(*) total FROM users GROUP BY role') as $r) {
    $counts[$r['role']] = $r;
}
$ago = function (?string $at): string {
    if ($at === null) {
        return 'Never';
    }
    $d = (int) floor((time() - strtotime($at)) / 86400);
    return $d <= 0 ? 'Today' : ($d === 1 ? 'Yesterday' : "$d days ago");
};

$title = 'Users';
$active = 'users';
require __DIR__ . '/../../src/views/staff_top.php';
?>
<div class="page-head">
  <div><h1>Users</h1>
  <p class="lede">Every account in NetForge. Create staff and student accounts, change roles, or switch an account off. Deactivated accounts keep their progress and can be switched back on.</p></div>
  <p class="stat"><?php foreach (Accounts::ROLES as $r): $c = $counts[$r] ?? ['active' => 0, 'total' => 0]; ?>
    <span class="role-count"><b class="mono"><?= (int) $c['active'] ?></b> <?= e($r) ?><?= (int) $c['active'] === 1 ? '' : 's' ?></span>
  <?php endforeach; ?></p>
</div>

<form class="filters" method="get">
  <label class="grow">Search <input type="search" name="q" value="<?= e($q) ?>" placeholder="Username, name or email"></label>
  <label>Role <select name="role"><option value="">Any role</option>
    <?php foreach (Accounts::ROLES as $r): ?><option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></label>
  <label>Status <select name="status"><option value="">Active and off</option>
    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Deactivated</option></select></label>
  <button class="btn">Filter</button>
</form>

<p class="count"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?><?= count($users) >= 200 ? ' (first 200, narrow the search)' : '' ?></p>
<table class="data users">
  <thead><tr><th scope="col">Account</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col" class="num">Level</th><th scope="col">Last sign-in</th><th scope="col">Status</th><th scope="col" class="act"><span class="visually-hidden">Actions</span></th></tr></thead>
  <tbody>
  <?php foreach ($users as $u): ?>
    <tr class="<?= $u['active'] ? '' : 'dim' ?>">
      <th scope="row"><span class="who-cell"><canvas data-avatar="<?= e($u['avatar']) ?>" data-scale="1" aria-hidden="true"></canvas>
        <span><?= e(Accounts::name($u)) ?><?php if ($u['display_name']): ?><small class="mono"><?= e($u['username']) ?></small><?php endif; ?></span></span></th>
      <td><?= $u['email'] ? e($u['email']) : '<span class="muted">None</span>' ?></td>
      <td><span class="role <?= e($u['role']) ?>"><?= e(ucfirst($u['role'])) ?></span></td>
      <td class="num mono"><?= $u['role'] === 'student' ? (int) $u['level'] : '–' ?></td>
      <td class="nowrap"><?= e($ago($u['last_login_at'])) ?></td>
      <td><?= $u['active'] ? '<span class="verdict ok"><i class="ti ti-circle-check" aria-hidden="true"></i>Active</span>' : '<span class="verdict bad"><i class="ti ti-ban" aria-hidden="true"></i>Off</span>' ?></td>
      <td class="act"><a class="btn sm" href="user.php?id=<?= (int) $u['id'] ?>">Manage</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$users): ?><tr><td colspan="7" class="empty">No accounts match. Clear the filters to see everyone.</td></tr><?php endif; ?>
  </tbody>
</table>

<section class="panel" id="create">
  <h2>Create an account</h2>
  <form method="post" class="form">
    <?= Csrf::field() ?><input type="hidden" name="action" value="create">
    <div class="grid2">
      <label>Username <input name="username" value="<?= e($form['username']) ?>" required autocomplete="off"><small>3 to 32 letters, numbers or underscores</small></label>
      <label>Email <input type="email" name="email" value="<?= e($form['email']) ?>" required autocomplete="off"><small>For password reset links</small></label>
      <label>Role <select name="role">
        <?php foreach (Accounts::ROLES as $r): ?><option value="<?= $r ?>" <?= $form['role'] === $r ? 'selected' : '' ?>><?= ucfirst($r) ?></option><?php endforeach; ?></select></label>
      <label>Temporary password <input type="text" name="password" required autocomplete="off" minlength="8"><small>At least 8 characters. They can change it in their profile.</small></label>
    </div>
    <p class="help">Students start in the garage with the busted laptop, like anyone who signs up. Lecturers and admins land on these pages.</p>
    <div class="actions"><button class="btn primary">Create account</button></div>
  </form>
</section>
<script type="module">import { paintAvatars } from '../assets/js/game/art.js'; paintAvatars();</script>
<?php require __DIR__ . '/../../src/views/staff_bottom.php'; ?>
