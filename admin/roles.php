<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login();
require_perm('roles.view');
$pdo = db(); $me = (int) $admin['id'];
$all = all_permissions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    deny_unless(['save' => !empty($_POST['id']) ? 'roles.edit' : 'roles.add', 'delete' => 'roles.delete', 'copy' => 'roles.add'][$act] ?? 'roles.edit', 'roles.php');
    $id = (int) ($_POST['id'] ?? 0);
    if ($act === 'save') {
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80); $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255);
        $perms = array_values(array_intersect($all, (array) ($_POST['perms'] ?? [])));
        // "view" is needed for anything else in the same section
        foreach ($perms as $p) { $v = explode('.', $p)[0] . '.view'; if (in_array($v, $all, true) && !in_array($v, $perms, true)) $perms[] = $v; }
        $x = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = ? AND id <> ?'); $x->execute([$name, $id]);
        if (mb_strlen($name) < 2) { flash('Give the role a name.', 'bad'); redirect('roles.php' . ($id ? '?edit=' . $id : '?new=1')); }
        if ((int) $x->fetchColumn()) { flash('Another role already has that name.', 'bad'); redirect('roles.php' . ($id ? '?edit=' . $id : '?new=1')); }
        $pdo->beginTransaction();
        if ($id) $pdo->prepare('UPDATE roles SET name = ?, description = ?, updated_at = ? WHERE id = ?')->execute([$name, $desc ?: null, now(), $id]);
        else { $pdo->prepare('INSERT INTO roles (name, description, created_at, updated_at) VALUES (?,?,?,?)')->execute([$name, $desc ?: null, now(), now()]); $id = (int) $pdo->lastInsertId(); }
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO role_permissions (role_id, permission) VALUES (?,?)');
        foreach (array_unique($perms) as $p) $ins->execute([$id, $p]);
        $pdo->commit();
        log_activity($me, 'role_saved', $name, count($perms) . ' permissions');
        flash('Role "' . $name . '" saved with ' . count(array_unique($perms)) . ' permissions.');
    } elseif ($act === 'copy') {
        $r = $pdo->prepare('SELECT * FROM roles WHERE id = ?'); $r->execute([$id]); $r = $r->fetch();
        if ($r) {
            $n = $r['name'] . ' (copy)'; $i = 2;
            while (true) { $x = $pdo->prepare('SELECT COUNT(*) FROM roles WHERE name = ?'); $x->execute([$n]); if (!(int) $x->fetchColumn()) break; $n = $r['name'] . ' (copy ' . $i++ . ')'; }
            $pdo->prepare('INSERT INTO roles (name, description, created_at, updated_at) VALUES (?,?,?,?)')->execute([$n, $r['description'], now(), now()]); $nid = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO role_permissions (role_id, permission) SELECT ?, permission FROM role_permissions WHERE role_id = ?')->execute([$nid, $id]);
            flash('Role copied. Rename it and adjust the permissions.'); redirect('roles.php?edit=' . $nid);
        }
    } elseif ($act === 'delete') {
        $r = $pdo->prepare('SELECT name FROM roles WHERE id = ?'); $r->execute([$id]); $name = $r->fetchColumn();
        if ($name !== false) {
            $pdo->prepare('UPDATE admins SET role_id = NULL, updated_at = ? WHERE role_id = ?')->execute([now(), $id]);
            $pdo->prepare('DELETE FROM role_permissions WHERE role_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM roles WHERE id = ?')->execute([$id]);
            log_activity($me, 'role_deleted', (string) $name); flash('Role "' . $name . '" deleted. Admins who had it now have no role.');
        }
    }
    redirect('roles.php');
}

$roles = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM role_permissions p WHERE p.role_id = r.id) perms, (SELECT COUNT(*) FROM admins a WHERE a.role_id = r.id AND a.role = \'admin\') people FROM roles r ORDER BY r.name')->fetchAll();
$supers = (int) $pdo->query("SELECT COUNT(*) FROM admins WHERE role = 'super_admin'")->fetchColumn();
$edit = null; $editPerms = [];
if (isset($_GET['edit'])) { foreach ($roles as $r) if ((int) $r['id'] === (int) $_GET['edit']) $edit = $r; if ($edit) { $s = $pdo->prepare('SELECT permission FROM role_permissions WHERE role_id = ?'); $s->execute([$edit['id']]); $editPerms = $s->fetchAll(PDO::FETCH_COLUMN); } }
$creating = isset($_GET['new']) && can('roles.add');
$canSave = $edit ? can('roles.edit') : $creating;
$actionsAll = []; foreach (PERMISSIONS as [, $acts]) foreach ($acts as $a => $_) $actionsAll[$a] = ucfirst($a);

page_head($edit ? 'Role: ' . $edit['name'] : ($creating ? 'New role' : 'Roles and permissions'), $admin, 'roles', [['Business settings', 'settings.php'], ['Roles and permissions', ($edit || $creating) ? 'roles.php' : null]] + (($edit || $creating) ? [2 => [$edit ? $edit['name'] : 'New', null]] : []),
    !$edit && !$creating && can('roles.add') ? '<a class="btn btn-primary btn-sm" href="roles.php?new=1"><i class="bi bi-plus-lg"></i> New role</a>' : '');

if ($edit || $creating): ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= $edit ? (int) $edit['id'] : 0 ?>">
<div class="card"><div class="card-body row g-3">
  <div class="col-md-4"><label class="form-label" for="r-n">Role name</label><input id="r-n" class="form-control" name="name" required maxlength="80" value="<?= h($edit['name'] ?? '') ?>" placeholder="e.g. Accountant"<?= $canSave ? '' : ' disabled' ?>></div>
  <div class="col-md-8"><label class="form-label" for="r-d">Description</label><input id="r-d" class="form-control" name="description" maxlength="255" value="<?= h((string) ($edit['description'] ?? '')) ?>" placeholder="What people with this role do"<?= $canSave ? '' : ' disabled' ?>></div>
</div></div>
<div class="card"><div class="card-header"><h3 class="card-title">Permissions</h3><div class="card-tools small text-secondary">Ticking any action also gives View for that section.</div></div>
<div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 perm-table">
  <thead><tr><th>Section</th><?php foreach ($actionsAll as $a => $l): ?><th class="text-center"><?= h($l) ?></th><?php endforeach; ?><th class="text-center">All</th></tr></thead><tbody>
  <?php foreach (PERMISSIONS as $mod => [$label, $acts]): ?><tr>
    <td><b><?= h($label) ?></b></td>
    <?php foreach ($actionsAll as $a => $_): $k = "$mod.$a"; ?><td class="text-center"><?php if (isset($acts[$a])): ?><input class="form-check-input" type="checkbox" name="perms[]" value="<?= h($k) ?>" data-mod="<?= h($mod) ?>" title="<?= h($acts[$a]) ?>" aria-label="<?= h($label . ': ' . $acts[$a]) ?>"<?= in_array($k, $editPerms, true) ? ' checked' : '' ?><?= $canSave ? '' : ' disabled' ?>><?php else: ?><span class="text-body-tertiary">–</span><?php endif; ?></td><?php endforeach; ?>
    <td class="text-center"><input class="form-check-input" type="checkbox" data-row-all="<?= h($mod) ?>" aria-label="All for <?= h($label) ?>"<?= $canSave ? '' : ' disabled' ?>></td>
  </tr><?php endforeach; ?></tbody></table></div></div></div>
<?php if ($canSave): ?><div class="d-flex gap-2 mb-4"><button class="btn btn-primary"><i class="bi bi-check2"></i> Save role</button><a class="btn btn-outline-secondary" href="roles.php">Cancel</a></div><?php endif; ?>
</form>
<?php else: ?>
<p class="text-secondary">A role is a set of permissions. Give each admin a role on the <a href="admins.php">Admin users</a> page. Changes apply on the admin's next click.</p>
<div class="row g-3">
  <div class="col-md-6 col-xl-4"><div class="card h-100 card-outline card-primary"><div class="card-body"><h5 class="card-title d-block mb-1"><i class="bi bi-stars text-primary"></i> Super admin</h5><p class="small text-secondary mb-2">Built in. Can do everything, including managing roles and other super admins.</p><span class="badge text-bg-light"><?= $supers ?> admin<?= $supers === 1 ? '' : 's' ?></span> <span class="badge text-bg-light">all permissions</span></div></div></div>
  <?php foreach ($roles as $r): ?>
  <div class="col-md-6 col-xl-4"><div class="card h-100"><div class="card-body"><h5 class="card-title d-block mb-1"><?= h($r['name']) ?></h5><p class="small text-secondary mb-2"><?= h((string) $r['description']) ?: '&nbsp;' ?></p>
    <span class="badge text-bg-light"><?= (int) $r['people'] ?> admin<?= (int) $r['people'] === 1 ? '' : 's' ?></span> <span class="badge text-bg-light"><?= (int) $r['perms'] ?> of <?= count($all) ?> permissions</span></div>
    <div class="card-footer d-flex gap-2">
      <a class="btn btn-sm btn-outline-primary" href="roles.php?edit=<?= (int) $r['id'] ?>"><i class="bi bi-<?= can('roles.edit') ? 'pencil' : 'eye' ?>"></i> <?= can('roles.edit') ? 'Edit' : 'View' ?></a>
      <?php if (can('roles.add')): ?><form method="post" class="m-0"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-secondary"><i class="bi bi-copy"></i> Copy</button></form><?php endif; ?>
      <?php if (can('roles.delete')): ?><form method="post" class="m-0 ms-auto"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete the role <?= h($r['name']) ?>?<?= (int) $r['people'] ? ' ' . (int) $r['people'] . ' admin(s) will be left without a role.' : '' ?>"><i class="bi bi-trash3"></i></button></form><?php endif; ?>
    </div></div></div>
  <?php endforeach; ?>
</div>
<?php endif; page_foot();
