<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('team.manage'); biz_need_module('team'); $a = account(); $pdo = db(); $aid = (int) $a['id']; $can = $a['_sub']['ok']; $err = []; $tab = in_array($_GET['tab'] ?? '', ['roles', 'activity'], true) ? $_GET['tab'] : 'users';
$roles = $pdo->prepare('SELECT * FROM biz_roles WHERE account_id = ? ORDER BY name'); $roles->execute([$aid]); $roles = $roles->fetchAll(); $branches = biz_branches($aid);
$owner = $u['role'] === 'owner';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    $act = (string) ($_POST['action'] ?? ''); $uid = (int) ($_POST['uid'] ?? 0);
    $roleOk = fn(int $r) => (bool) array_filter($roles, fn($x) => (int) $x['id'] === $r); $brOk = fn(int $b) => (bool) array_filter($branches, fn($x) => (int) $x['id'] === $b);
    $target = null; if ($uid) { $s = $pdo->prepare('SELECT * FROM biz_users WHERE id = ? AND account_id = ?'); $s->execute([$uid, $aid]); $target = $s->fetch() ?: null; }
    if ($act === 'adduser') {
        biz_no_imp(); $n = mb_substr(trim((string) ($_POST['full_name'] ?? '')), 0, 190); $em = strtolower(trim((string) ($_POST['email'] ?? ''))); $pw = (string) ($_POST['password'] ?? ''); $rid = (int) ($_POST['role_id'] ?? 0); $bid = (int) ($_POST['branch_id'] ?? 0);
        $x = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE email = ?'); $x->execute([$em]); $cnt = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE account_id = ? AND is_active = 1'); $cnt->execute([$aid]); $lim = biz_limit($a, 'max_users');
        if ($n === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) $err[] = 'Enter the name and a valid email.';
        elseif (!$roleOk($rid)) $err[] = 'Choose a role.';
        elseif ($e = valid_password($pw)) $err[] = $e;
        elseif ((int) $x->fetchColumn()) $err[] = 'That email already has an account.';
        elseif ($lim > 0 && (int) $cnt->fetchColumn() >= $lim) $err[] = "Your plan allows $lim active users. Upgrade under Subscription to add more.";
        else {
            $pdo->prepare("INSERT INTO biz_users (account_id, full_name, email, password_hash, role, must_change_pw, created_at) VALUES (?,?,?,?, 'staff', 1, ?)")->execute([$aid, $n, $em, make_hash($pw), gmdate('Y-m-d H:i:s')]); $nid = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO biz_user_ext (user_id, account_id, role_id, branch_id) VALUES (?,?,?,?)')->execute([$nid, $aid, $rid, $brOk($bid) ? $bid : null]);
            biz_audit('user_added', $em); flash('Team member added. They must change the password at first sign-in.'); redirect('team.php');
        }
    } elseif ($act === 'edituser' && $target && $target['role'] !== 'owner') {
        $rid = (int) ($_POST['role_id'] ?? 0); $bid = (int) ($_POST['branch_id'] ?? 0);
        if ($roleOk($rid)) { $pdo->prepare('DELETE FROM biz_user_ext WHERE user_id = ?')->execute([$uid]); $pdo->prepare('INSERT INTO biz_user_ext (user_id, account_id, role_id, branch_id) VALUES (?,?,?,?)')->execute([$uid, $aid, $rid, $brOk($bid) ? $bid : null]); biz_audit('user_role', $target['email']); flash('Saved.'); }
        redirect('team.php');
    } elseif ($act === 'toggle' && $target && $target['role'] !== 'owner') {
        biz_no_imp(); $pdo->prepare('UPDATE biz_users SET is_active = 1 - is_active WHERE id = ?')->execute([$uid]); biz_audit('user_toggle', $target['email']); redirect('team.php');
    } elseif ($act === 'resetpw' && $target && $target['role'] !== 'owner') {
        biz_no_imp(); $pw = temp_password(); $pdo->prepare('UPDATE biz_users SET password_hash = ?, must_change_pw = 1, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([make_hash($pw), $uid]); biz_audit('user_pw_reset', $target['email']);
        flash('New temporary password for ' . $target['email'] . ': ' . $pw . ' (shown once; they must change it at sign-in).'); redirect('team.php');
    } elseif ($act === 'saverole') {
        $rid = (int) ($_POST['role_id'] ?? 0); $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80); $perms = array_values(array_intersect(array_keys(BIZ_PERMS), (array) ($_POST['perms'] ?? [])));
        if ($name === '') $err[] = 'Role name is required.';
        else {
            if ($rid && $roleOk($rid)) $pdo->prepare('UPDATE biz_roles SET name = ?, perms = ?, all_branches = ? WHERE id = ? AND account_id = ?')->execute([$name, implode(',', $perms), empty($_POST['all_branches']) ? 0 : 1, $rid, $aid]);
            else $pdo->prepare('INSERT INTO biz_roles (account_id, name, perms, all_branches) VALUES (?,?,?,?)')->execute([$aid, $name, implode(',', $perms), empty($_POST['all_branches']) ? 0 : 1]);
            biz_audit('role_saved', $name); flash('Role saved.'); redirect('team.php?tab=roles');
        }
        $tab = 'roles';
    }
}
$users = $pdo->prepare('SELECT u.*, x.role_id, x.branch_id FROM biz_users u LEFT JOIN biz_user_ext x ON x.user_id = u.id WHERE u.account_id = ? ORDER BY u.role DESC, u.id'); $users->execute([$aid]); $users = $users->fetchAll();
$rn = []; foreach ($roles as $r) $rn[$r['id']] = $r['name']; $bn = []; foreach (biz_branches($aid, false) as $b) $bn[$b['id']] = $b['name'];
$editRole = null; if (isset($_GET['role'])) foreach ($roles as $r) if ((int) $r['id'] === (int) $_GET['role']) $editRole = $r;
biz_head('Team', 'team'); ?>
<div class="chips"><a href="team.php" class="<?= $tab === 'users' ? 'on' : '' ?>">People</a><a href="team.php?tab=roles" class="<?= $tab === 'roles' ? 'on' : '' ?>">Roles</a><?php if (bcan('audit.view')): ?><a href="team.php?tab=activity" class="<?= $tab === 'activity' ? 'on' : '' ?>">Activity</a><?php endif; ?></div>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($tab === 'users'): ?>
<div class="card flush"><div class="list"><?php foreach ($users as $x): ?><div class="row<?= $x['is_active'] ? '' : ' dim' ?>"><div><b><?= h($x['full_name']) ?></b><small><?= h($x['email']) ?> · <?= $x['role'] === 'owner' ? 'Business Owner' : h($rn[$x['role_id']] ?? 'No role') ?><?= $x['branch_id'] ? ' · ' . h($bn[$x['branch_id']] ?? '') : '' ?><?= $x['last_login'] ? ' · last seen ' . h(fmt_date(substr($x['last_login'], 0, 10))) : '' ?></small></div>
<div class="r"><?php if ($can && $x['role'] !== 'owner'): ?>
<form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int) $x['id'] ?>"><input type="hidden" name="action" value="edituser"><select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"<?= (int) $x['role_id'] === (int) $r['id'] ? ' selected' : '' ?>><?= h($r['name']) ?></option><?php endforeach; ?></select>
<select name="branch_id"><option value="">All branches</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"<?= (int) $x['branch_id'] === (int) $b['id'] ? ' selected' : '' ?>><?= h($b['name']) ?></option><?php endforeach; ?></select><button class="btn sm ghost">Save</button></form>
<form method="post" class="inl"><?= csrf_field() ?><input type="hidden" name="uid" value="<?= (int) $x['id'] ?>"><button class="btn sm ghost" name="action" value="toggle"><?= $x['is_active'] ? 'Deactivate' : 'Activate' ?></button><button class="btn sm ghost" name="action" value="resetpw" data-confirm="Reset this person's password?">Reset password</button></form><?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php if ($can): ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="adduser"><h2>Add team member</h2>
<div class="two"><label>Name<input name="full_name" maxlength="190" required></label><label>Email<input name="email" type="email" maxlength="190" required></label>
<label>Role<select name="role_id"><?php foreach ($roles as $r): ?><option value="<?= (int) $r['id'] ?>"><?= h($r['name']) ?></option><?php endforeach; ?></select></label><label>Branch<select name="branch_id"><option value="">All branches</option><?php foreach ($branches as $b): ?><option value="<?= (int) $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?></select></label></div>
<label>Temporary password<input name="password" required minlength="8"></label><div class="actions"><button class="btn">Add</button></div></form><?php endif; ?>
<?php elseif ($tab === 'roles'): ?>
<p class="muted">Roles decide what each person can do. Edit them or make your own. People assigned to a role that is not "all branches" only see their own branch.</p>
<div class="card flush"><div class="list"><?php foreach ($roles as $r): ?><div class="row"><div><b><?= h($r['name']) ?></b><small><?= count(array_filter(explode(',', $r['perms']))) ?> permissions<?= $r['all_branches'] ? ' · all branches' : ' · own branch' ?></small></div><div class="r"><a class="btn sm ghost" href="team.php?tab=roles&role=<?= (int) $r['id'] ?>">Edit</a></div></div><?php endforeach; ?></div></div>
<?php if ($can): $cur = $editRole ? explode(',', $editRole['perms']) : []; ?><form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="saverole"><input type="hidden" name="role_id" value="<?= (int) ($editRole['id'] ?? 0) ?>"><h2><?= $editRole ? 'Edit role' : 'New role' ?></h2>
<label>Role name<input name="name" required maxlength="80" value="<?= h((string) ($editRole['name'] ?? '')) ?>"></label><div class="checks"><?php foreach (BIZ_PERMS as $k => $l): ?><label class="chk"><input type="checkbox" name="perms[]" value="<?= h($k) ?>"<?= in_array($k, $cur, true) ? ' checked' : '' ?>> <?= h($l) ?></label><?php endforeach; ?></div>
<label class="chk"><input type="checkbox" name="all_branches" value="1"<?= !$editRole || $editRole['all_branches'] ? ' checked' : '' ?>> Can work across all branches</label><div class="actions"><?php if ($editRole): ?><a class="btn ghost" href="team.php?tab=roles">Cancel</a><?php endif; ?><button class="btn">Save role</button></div></form><?php endif; ?>
<?php else: biz_need('audit.view');
  $au = $pdo->prepare('SELECT a.*, u.full_name FROM biz_audit a LEFT JOIN biz_users u ON u.id = a.user_id WHERE a.account_id = ? ORDER BY a.id DESC LIMIT 60'); $au->execute([$aid]); $au = $au->fetchAll();
  $ll = $pdo->prepare('SELECT * FROM biz_login_log WHERE account_id = ? ORDER BY id DESC LIMIT 30'); $ll->execute([$aid]); $ll = $ll->fetchAll(); ?>
<div class="card"><h2>Recent activity</h2><div class="list"><?php foreach ($au as $x): ?><div class="row"><div><b><?= h($x['action']) ?></b><small><?= h($x['detail'] ?? '') ?></small></div><div class="r"><small><?= h($x['full_name'] ?? '') ?><?= $x['admin_id'] ? ' (Audtix support: ' . h((string) $x['admin_id']) . ')' : '' ?></small><small><?= h($x['created_at']) ?> UTC</small></div></div><?php endforeach; ?><?php if (!$au): ?><p class="muted">Nothing yet.</p><?php endif; ?></div></div>
<div class="card"><h2>Sign-in attempts</h2><div class="list"><?php foreach ($ll as $x): ?><div class="row"><div><b><?= h((string) $x['email']) ?></b><small><?= $x['ok'] ? 'Signed in' : 'Failed' ?> · <?= h((string) $x['ip']) ?></small></div><div class="r"><small><?= h($x['created_at']) ?> UTC</small></div></div><?php endforeach; ?></div></div>
<?php endif; biz_foot();
