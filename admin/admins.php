<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('admins.view');
$pdo = db(); $secret = null; $secretFor = ''; $me = (int) $admin['id']; $iAmSuper = is_super($admin);
$roles = $pdo->query('SELECT r.id, r.name, r.description, (SELECT COUNT(*) FROM role_permissions p WHERE p.role_id = r.id) perms FROM roles r ORDER BY r.name')->fetchAll();
$roleIds = array_map(fn($r) => (int) $r['id'], $roles);

/** Turns the role picker value ("super" or a role id) into [role, role_id] or null if not allowed. */
function pick_role(string $v, array $roleIds, bool $iAmSuper): ?array {
    if ($v === 'super') return $iAmSuper ? ['super_admin', null] : null;
    if ($v === 'none') return ['admin', null];
    return in_array((int) $v, $roleIds, true) ? ['admin', (int) $v] : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    $need = ['create' => 'admins.add', 'delete' => 'admins.delete'][$act] ?? 'admins.edit';
    deny_unless($need, 'admins.php');
    $activeSupers = fn() => (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin' AND is_active = 1")->fetchColumn();
    $clean = fn($k, $n) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    if ($act === 'create') {
        $u = strtolower($clean('username', 40)); $em = strtolower($clean('email', 190)); $fn = $clean('full_name', 120);
        $role = pick_role((string) ($_POST['role'] ?? ''), $roleIds, $iAmSuper);
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) flash('Username: 3 to 40 letters, numbers, dot, dash or underscore.', 'bad');
        elseif (!filter_var($em, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email address.', 'bad');
        elseif (mb_strlen($fn) < 2) flash('Full name is required.', 'bad');
        elseif (!$role) flash('Choose a role you are allowed to give.', 'bad');
        else {
            $x = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ? OR email = ?'); $x->execute([$u, $em]);
            if ((int) $x->fetchColumn()) flash('That username or email is already used.', 'bad');
            else {
                $pw = temp_password();
                $pdo->prepare('INSERT INTO admins (username, email, full_name, phone, job_title, role, role_id, password_hash, is_active, must_change_pw, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,1,1,?,?,?)')
                    ->execute([$u, $em, $fn, $clean('phone', 40) ?: null, $clean('job_title', 120) ?: null, $role[0], $role[1], make_hash($pw), $me, now(), now()]);
                log_activity($me, 'admin_created', $u, $role[0] . ($role[1] ? ':' . $role[1] : ''));
                $secret = $pw; $secretFor = $u; flash('Admin created. Copy the temporary password now, it is shown only once.');
            }
        }
    } else {
        $tid = (int) ($_POST['id'] ?? 0);
        $t = $pdo->prepare('SELECT * FROM admins WHERE id = ?'); $t->execute([$tid]); $t = $t->fetch();
        if (!$t) flash('Admin not found.', 'bad');
        elseif ($t['role'] === 'super_admin' && !$iAmSuper) flash('Only a super admin can change a super admin account.', 'bad');
        elseif ($act === 'update') {
            $em = strtolower($clean('email', 190)); $fn = $clean('full_name', 120);
            $role = (int) $t['id'] === $me ? [$t['role'], $t['role_id'] ? (int) $t['role_id'] : null] : pick_role((string) ($_POST['role'] ?? ''), $roleIds, $iAmSuper);
            $x = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE email = ? AND id <> ?'); $x->execute([$em, $tid]);
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) flash('Enter a valid email address.', 'bad');
            elseif (mb_strlen($fn) < 2) flash('Full name is required.', 'bad');
            elseif ((int) $x->fetchColumn()) flash('Another admin already uses that email.', 'bad');
            elseif (!$role) flash('Choose a role you are allowed to give.', 'bad');
            elseif ($t['role'] === 'super_admin' && $role[0] !== 'super_admin' && (int) $t['is_active'] && $activeSupers() <= 1) flash('There must always be one active super admin.', 'bad');
            else {
                $pdo->prepare('UPDATE admins SET full_name = ?, email = ?, phone = ?, job_title = ?, role = ?, role_id = ?, updated_at = ? WHERE id = ?')
                    ->execute([$fn, $em, $clean('phone', 40) ?: null, $clean('job_title', 120) ?: null, $role[0], $role[1], now(), $tid]);
                log_activity($me, 'admin_updated', $t['username'], $role[0] . ($role[1] ? ':' . $role[1] : ''));
                flash('Changes saved for ' . $t['username'] . '.'); redirect('admins.php');
            }
            redirect('admins.php?edit=' . $tid);
        } elseif ($act === 'toggle') {
            if ((int) $t['id'] === $me) flash('You cannot deactivate your own account.', 'bad');
            elseif ((int) $t['is_active'] && $t['role'] === 'super_admin' && $activeSupers() <= 1) flash('There must always be one active super admin.', 'bad');
            else { $n = (int) $t['is_active'] ? 0 : 1; $pdo->prepare('UPDATE admins SET is_active = ?, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([$n, now(), $tid]); log_activity($me, $n ? 'admin_activated' : 'admin_deactivated', $t['username']); flash($n ? 'Account activated.' : 'Account deactivated. They are signed out on their next click.'); }
        } elseif ($act === 'reset') {
            $pw = temp_password();
            $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_pw = 1, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?')->execute([make_hash($pw), now(), $tid]);
            log_activity($me, 'admin_password_reset', $t['username']); $secret = $pw; $secretFor = $t['username']; flash('Password reset. Copy the temporary password now, it is shown only once.');
        } elseif ($act === 'delete') {
            if ((int) $t['id'] === $me) flash('You cannot delete your own account.', 'bad');
            elseif ($t['role'] === 'super_admin' && $activeSupers() <= 1 && (int) $t['is_active']) flash('There must always be one active super admin.', 'bad');
            else { $pdo->prepare('DELETE FROM admins WHERE id = ?')->execute([$tid]); log_activity($me, 'admin_deleted', $t['username']); flash('Admin ' . $t['username'] . ' deleted. Their past work stays in the history.'); }
        }
    }
    if ($secret === null) redirect('admins.php');
}

$list = $pdo->query('SELECT a.*, c.full_name AS creator FROM admins a LEFT JOIN admins c ON c.id = a.created_by ORDER BY a.role DESC, a.full_name')->fetchAll();
$edit = null;
if (isset($_GET['edit']) && can('admins.edit')) foreach ($list as $a) if ((int) $a['id'] === (int) $_GET['edit']) $edit = $a;
if ($edit && $edit['role'] === 'super_admin' && !$iAmSuper) $edit = null;

function role_options(array $roles, bool $iAmSuper, string $current): string {
    $o = $iAmSuper ? '<option value="super"' . ($current === 'super' ? ' selected' : '') . '>Super admin (full access)</option>' : '';
    foreach ($roles as $r) $o .= '<option value="' . (int) $r['id'] . '"' . ($current === (string) $r['id'] ? ' selected' : '') . '>' . h($r['name']) . ' (' . (int) $r['perms'] . ' permissions)</option>';
    return $o . '<option value="none"' . ($current === 'none' ? ' selected' : '') . '>No role (can only sign in)</option>';
}
page_head('Admin users', $admin, 'admins', [['Business settings', 'settings.php'], ['Admin users', null]]);
?>
<p class="text-secondary">Each admin gets a role. The role decides what they can see and do. <?php if (can('roles.view')): ?><a href="roles.php">Manage roles and permissions</a>.<?php endif; ?> New admins get a one-time temporary password and choose their own at first sign-in.</p>
<?php if ($secret !== null): ?><div class="callout callout-warning mb-3"><h5>Temporary password for <?= h($secretFor) ?></h5><p class="secret mb-2"><?= h($secret) ?></p><p class="text-secondary mb-0">Send it to them privately. It will not be shown again.</p></div><?php endif; ?>
<div class="row g-3"><div class="<?= can('admins.add') || $edit ? 'col-xl-8' : 'col-12' ?>">
<div class="card"><div class="card-header"><h3 class="card-title">Admins (<?= count($list) ?>)</h3></div><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Last sign-in</th><th></th></tr></thead><tbody>
<?php foreach ($list as $a): $locked = $a['role'] === 'super_admin' && !$iAmSuper; $self = (int) $a['id'] === $me; ?><tr>
  <td><span class="avatar me-2"><?= h(mb_strtoupper(mb_substr($a['full_name'], 0, 1))) ?></span><b><?= h($a['full_name']) ?></b><?= $self ? ' <span class="badge text-bg-light">you</span>' : '' ?><br><span class="text-secondary small"><?= h($a['email']) ?><?= $a['job_title'] ? ' &middot; ' . h($a['job_title']) : '' ?></span></td>
  <td class="mono"><?= h($a['username']) ?></td>
  <td><span class="badge text-bg-<?= $a['role'] === 'super_admin' ? 'primary' : ($a['role_id'] ? 'info' : 'secondary') ?>"><?= h(role_name($a)) ?></span></td>
  <td><span class="badge text-bg-<?= (int) $a['is_active'] ? 'success' : 'danger' ?>"><?= (int) $a['is_active'] ? 'Active' : 'Disabled' ?></span><?= $a['locked_until'] && $a['locked_until'] > now() ? ' <span class="badge text-bg-danger">Locked</span>' : '' ?><?= (int) $a['must_change_pw'] ? '<br><small class="text-secondary">must set a new password</small>' : '' ?></td>
  <td class="small"><?= $a['last_login_at'] ? h(eat($a['last_login_at'])) : '<span class="text-secondary">Never</span>' ?></td>
  <td class="text-nowrap text-end"><?php if (!$locked && can('admins.edit')): ?>
    <a class="btn btn-sm btn-outline-primary" href="admins.php?edit=<?= (int) $a['id'] ?>" title="Edit" aria-label="Edit <?= h($a['username']) ?>"><i class="bi bi-pencil"></i></a>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="reset"><button class="btn btn-sm btn-outline-secondary" title="Reset password" aria-label="Reset password" data-confirm="Reset the password for <?= h($a['username']) ?>?"><i class="bi bi-key"></i></button></form>
    <?php if (!$self): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn-sm <?= (int) $a['is_active'] ? 'btn-outline-warning' : 'btn-success' ?>" title="<?= (int) $a['is_active'] ? 'Deactivate' : 'Activate' ?>" aria-label="<?= (int) $a['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= (int) $a['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i></button></form><?php endif; ?>
  <?php endif; if (!$locked && !$self && can('admins.delete')): ?>
    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $a['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete <?= h($a['username']) ?>" data-confirm="Delete <?= h($a['username']) ?>? Their past work stays in the history."><i class="bi bi-trash3"></i></button></form>
  <?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></div></div>
</div>
<?php if ($edit): $cur = $edit['role'] === 'super_admin' ? 'super' : ($edit['role_id'] ? (string) $edit['role_id'] : 'none'); ?>
<div class="col-xl-4"><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Edit <?= h($edit['username']) ?></h3><div class="card-tools"><a class="btn btn-tool" href="admins.php" aria-label="Close"><i class="bi bi-x-lg"></i></a></div></div><div class="card-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
  <div class="mb-2"><label class="form-label" for="e-fn">Full name</label><input id="e-fn" class="form-control" name="full_name" required maxlength="120" value="<?= h($edit['full_name']) ?>"></div>
  <div class="mb-2"><label class="form-label" for="e-em">Email</label><input id="e-em" class="form-control" name="email" type="email" required maxlength="190" value="<?= h($edit['email']) ?>"></div>
  <div class="mb-2"><label class="form-label" for="e-ph">Phone</label><input id="e-ph" class="form-control" name="phone" maxlength="40" value="<?= h((string) $edit['phone']) ?>"></div>
  <div class="mb-2"><label class="form-label" for="e-jt">Job title</label><input id="e-jt" class="form-control" name="job_title" maxlength="120" value="<?= h((string) $edit['job_title']) ?>"></div>
  <div class="mb-3"><label class="form-label" for="e-ro">Role</label><select id="e-ro" class="form-select" name="role"<?= (int) $edit['id'] === $me ? ' disabled' : '' ?>><?= role_options($roles, $iAmSuper, $cur) ?></select><?= (int) $edit['id'] === $me ? '<div class="form-text">You cannot change your own role.</div>' : '' ?></div>
  <button class="btn btn-primary">Save changes</button> <a class="btn btn-outline-secondary" href="admins.php">Cancel</a></form></div></div></div>
<?php elseif (can('admins.add')): ?>
<div class="col-xl-4"><div class="card card-outline card-primary"><div class="card-header"><h3 class="card-title">Add an admin</h3></div><div class="card-body">
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
  <div class="mb-2"><label class="form-label" for="a-fn">Full name</label><input id="a-fn" class="form-control" name="full_name" required maxlength="120"></div>
  <div class="mb-2"><label class="form-label" for="a-un">Username</label><input id="a-un" class="form-control" name="username" required maxlength="40" pattern="[A-Za-z0-9._\-]{3,40}" autocapitalize="none"></div>
  <div class="mb-2"><label class="form-label" for="a-em">Email</label><input id="a-em" class="form-control" name="email" type="email" required maxlength="190"></div>
  <div class="mb-2"><label class="form-label" for="a-ph">Phone (optional)</label><input id="a-ph" class="form-control" name="phone" maxlength="40"></div>
  <div class="mb-2"><label class="form-label" for="a-jt">Job title (optional)</label><input id="a-jt" class="form-control" name="job_title" maxlength="120"></div>
  <div class="mb-3"><label class="form-label" for="a-ro">Role</label><select id="a-ro" class="form-select" name="role" required><?= role_options($roles, $iAmSuper, $roles ? (string) $roles[0]['id'] : 'none') ?></select></div>
  <button class="btn btn-primary"><i class="bi bi-person-plus"></i> Create admin</button></form></div></div></div>
<?php endif; ?>
</div>
<?php page_foot();
