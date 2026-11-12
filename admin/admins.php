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
page_head('Admins', $admin, 'admins');
?>
<p class="text-secondary">Only super admins can see this page. New admins get a one-time temporary password and must choose their own at first sign-in.</p>
<?php if ($secret !== null): ?><div class="callout callout-warning mb-3"><h5>Temporary password for <?= h($secretFor) ?></h5><p class="secret mb-2"><?= h($secret) ?></p><p class="text-secondary mb-0">Send it to them privately. It will not be shown again.</p></div><?php endif; ?>
<div class="row g-3"><div class="col-xl-8">
<div class="card"><div class="card-header"><h3 class="card-title">Admin accounts</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Added by</th><th></th></tr></thead><tbody>
<?php foreach ($list as $a): ?><tr>
  <td><b><?= h($a['full_name']) ?></b><br><span class="text-secondary small"><?= h($a['email']) ?><?= $a['phone'] ? ' &middot; ' . h($a['phone']) : '' ?><?= $a['job_title'] ? '<br>' . h($a['job_title']) : '' ?></span></td>
  <td class="mono"><?= h($a['username']) ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="role"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><select name="role" class="form-select form-select-sm" data-autosubmit aria-label="Role"><option value="admin"<?= $a['role'] === 'admin' ? ' selected' : '' ?>>Admin</option><option value="super_admin"<?= $a['role'] === 'super_admin' ? ' selected' : '' ?>>Super admin</option></select></form></td>
  <td><span class="badge text-bg-<?= (int) $a['is_active'] ? 'success' : 'danger' ?>"><?= (int) $a['is_active'] ? 'Active' : 'Disabled' ?></span><?= $a['locked_until'] && $a['locked_until'] > now() ? ' <span class="badge text-bg-danger">Locked</span>' : '' ?><?= (int) $a['must_change_pw'] ? '<br><small class="text-secondary">must set a new password</small>' : '' ?></td>
  <td class="small"><?= $a['last_login_at'] ? h(eat($a['last_login_at'])) . '<br><span class="text-secondary">' . h($a['last_login_ip']) . '</span>' : '<span class="text-secondary">Never</span>' ?></td>
  <td class="small"><?= h($a['creator'] ?: 'Setup') ?></td>
  <td class="text-nowrap text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-sm btn-outline-secondary" data-confirm="Reset the password for <?= h($a['username']) ?>?"><i class="bi bi-key"></i> Reset</button></form>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-sm <?= (int) $a['is_active'] ? 'btn-outline-danger' : 'btn-success' ?>"><?= (int) $a['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
</tr><?php endforeach; ?></tbody></table></div></div></div>
</div><div class="col-xl-4">
<div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Add an admin</h3></div><div class="card-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="mb-2"><label class="form-label" for="a-fn">Full name</label><input id="a-fn" class="form-control" name="full_name" required maxlength="120"></div>
  <div class="mb-2"><label class="form-label" for="a-un">Username</label><input id="a-un" class="form-control" name="username" required maxlength="40" pattern="[A-Za-z0-9._-]{3,40}" autocapitalize="none"></div>
  <div class="mb-2"><label class="form-label" for="a-em">Email</label><input id="a-em" class="form-control" name="email" type="email" required maxlength="190"></div>
  <div class="mb-2"><label class="form-label" for="a-ph">Phone (optional)</label><input id="a-ph" class="form-control" name="phone" maxlength="40"></div>
  <div class="mb-2"><label class="form-label" for="a-jt">Job title (optional)</label><input id="a-jt" class="form-control" name="job_title" maxlength="120"></div>
  <div class="mb-3"><label class="form-label" for="a-ro">Role</label><select id="a-ro" class="form-select" name="role"><option value="admin">Admin (works on requests and quotations)</option><option value="super_admin">Super admin (also manages admins, deletes, sees the login log)</option></select></div>
  <button class="btn btn-primary"><i class="bi bi-person-plus"></i> Create admin</button></form></div></div>
</div></div>
<?php page_foot();
