<?php
require __DIR__ . '/inc/boot.php';
$admin = require_super();
$pdo = db(); $secret = null; $secretFor = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';
    $activeSupers = fn() => (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
    if ($act === 'create') {
        $u = strtolower(trim((string) $_POST['username'])); $em = strtolower(trim((string) $_POST['email'])); $fn = trim((string) $_POST['full_name']);
        $role = ($_POST['role'] ?? '') === 'super_admin' ? 'super_admin' : 'admin';
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) flash('Username: 3 to 40 letters, numbers, dot, dash or underscore.', 'bad');
        elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email address.', 'bad');
        elseif (mb_strlen($fn) < 2) flash('Full name is required.', 'bad');
        else {
            $x = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?'); $x->execute([$u, $em]);
            if ((int) $x->fetchColumn()) flash('That username or email is already used.', 'bad');
            else {
                $pw = temp_password();
                $pdo->prepare('INSERT INTO admins (username, email, full_name, phone, job_title, role, password_hash, is_active, must_change_pw, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,1,?,?,?)')
                    ->execute([$u, $em, mb_substr($fn, 0, 120), mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 40) ?: null, mb_substr(trim((string) ($_POST['job_title'] ?? '')), 0, 120) ?: null, $role, make_hash($pw), $admin['id'], now(), now()]);
                log_activity((int) $admin['id'], 'admin_created', $u, $role);
                $secret = $pw; $secretFor = $u; flash('Admin created. Copy the temporary password now, it is shown only once.');
            }
        }
    } else {
        $tid = (int) ($_POST['id'] ?? 0);
        $t = $pdo->prepare('SELECT * FROM admins WHERE id = ?'); $t->execute([$tid]); $t = $t->fetch();
        if (!$t) flash('Admin not found.', 'bad');
        elseif ($act === 'toggle') {
            if ((int) $t['id'] === (int) $admin['id']) flash('You cannot deactivate your own account.', 'bad');
            elseif ((int) $t['is_active'] && $t['role'] === 'super_admin' && $activeSupers() <= 1) flash('There must always be one active super admin.', 'bad');
            else { $n = (int) $t['is_active'] ? 0 : 1; $pdo->prepare('UPDATE admins SET is_active = ?, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([$n, now(), $tid]); log_activity((int) $admin['id'], $n ? 'admin_activated' : 'admin_deactivated', $t['username']); flash($n ? 'Account activated.' : 'Account deactivated. They are signed out on their next click.'); }
        } elseif ($act === 'reset') {
            $pw = temp_password();
            $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_pw = 1, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([make_hash($pw), now(), $tid]);
            log_activity((int) $admin['id'], 'admin_password_reset', $t['username']); $secret = $pw; $secretFor = $t['username']; flash('Password reset. Copy the temporary password now, it is shown only once.');
        } elseif ($act === 'role') {
            $role = ($_POST['role'] ?? '') === 'super_admin' ? 'super_admin' : 'admin';
            if ((int) $t['id'] === (int) $admin['id'] && $role !== 'super_admin') flash('You cannot remove your own super admin role.', 'bad');
            else { $pdo->prepare('UPDATE admins SET role = ?, updated_at = ? WHERE id = ?')->execute([$role, now(), $tid]); log_activity((int) $admin['id'], 'admin_role', $t['username'], $role); flash('Role updated.'); }
        }
    }
    if ($secret === null) redirect('admins.php');
}
$list = $pdo->query('SELECT a.*, c.full_name AS creator FROM admins a LEFT JOIN admins c ON c.id = a.created_by ORDER BY a.id')->fetchAll();
page_head('Admins', $admin);
?>
<h1>Admins</h1><p class="muted">Only super admins can see this page. New admins get a one-time temporary password and must choose their own at first sign-in.</p>
<?php if ($secret !== null): ?><div class="card"><h2>Temporary password for <?= h($secretFor) ?></h2><p class="secret"><?= h($secret) ?></p><p class="muted">Send it to them privately. It will not be shown again.</p></div><?php endif; ?>
<div class="card tablewrap" style="padding:0"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Added by</th><th></th></tr></thead><tbody>
<?php foreach ($list as $a): ?><tr>
  <td><b><?= h($a['full_name']) ?></b><br><span class="muted"><?= h($a['email']) ?><?= $a['phone'] ? ' &middot; ' . h($a['phone']) : '' ?><?= $a['job_title'] ? '<br>' . h($a['job_title']) : '' ?></span></td>
  <td class="mono"><?= h($a['username']) ?></td>
  <td><form method="post" style="display:flex;gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><select name="role" onchange="this.form.submit()"><option value="admin"<?= $a['role'] === 'admin' ? ' selected' : '' ?>>Admin</option><option value="super_admin"<?= $a['role'] === 'super_admin' ? ' selected' : '' ?>>Super admin</option></select></form></td>
  <td><span class="pill <?= (int) $a['is_active'] ? 'good' : 'bad' ?>"><?= (int) $a['is_active'] ? 'Active' : 'Disabled' ?></span><?= $a['locked_until'] && $a['locked_until'] > now() ? ' <span class="pill bad">Locked</span>' : '' ?><?= (int) $a['must_change_pw'] ? '<br><small class="muted">must set a new password</small>' : '' ?></td>
  <td><?= $a['last_login_at'] ? h(eat($a['last_login_at'])) . '<br><small class="muted">' . h($a['last_login_ip']) . '</small>' : '<span class="muted">Never</span>' ?></td>
  <td><?= h($a['creator'] ?: 'Setup') ?></td>
  <td style="white-space:nowrap"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="reset"><button class="btn sm ghost" onclick="return confirm('Reset the password for <?= h($a['username']) ?>?')">Reset password</button></form>
    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn sm <?= (int) $a['is_active'] ? 'danger' : '' ?>"><?= (int) $a['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
</tr><?php endforeach; ?></tbody></table></div>
<div class="card"><h2>Add an admin</h2>
<form method="post" class="stack" style="max-width:520px"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <label>Full name<input name="full_name" required maxlength="120"></label>
  <label>Username<input name="username" required maxlength="40" pattern="[A-Za-z0-9._-]{3,40}" autocapitalize="none"></label>
  <label>Email<input name="email" type="email" required maxlength="190"></label>
  <label>Phone (optional)<input name="phone" maxlength="40"></label>
  <label>Job title (optional)<input name="job_title" maxlength="120"></label>
  <label>Role<select name="role"><option value="admin">Admin (works on requests)</option><option value="super_admin">Super admin (also manages admins and sees the login log)</option></select></label>
  <button class="btn">Create admin</button></form></div>
<?php page_foot();
