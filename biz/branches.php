<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('branches.manage'); biz_need_module('branches'); $a = account(); $pdo = db(); $aid = (int) $a['id']; $can = $a['_sub']['ok']; $err = [];
$users = $pdo->prepare('SELECT id, full_name FROM biz_users WHERE account_id = ? AND is_active = 1 ORDER BY full_name'); $users->execute([$aid]); $users = $users->fetchAll();
$id = (int) ($_GET['edit'] ?? 0); $e = null; foreach (biz_branches($aid, false) as $b) if ((int) $b['id'] === $id) $e = $b;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    $pid = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'toggle' && $pid) { $pdo->prepare('UPDATE biz_branches SET is_active = 1 - is_active WHERE id = ? AND account_id = ?')->execute([$pid, $aid]); biz_audit('branch_toggle', (string) $pid); redirect('branches.php'); }
    $f = ['name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 190), 'location' => mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 500), 'phone' => mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 60), 'email' => mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 190)];
    $mgr = (int) ($_POST['manager_user_id'] ?? 0); $okM = false; foreach ($users as $x) if ((int) $x['id'] === $mgr) $okM = true; if (!$okM) $mgr = 0;
    if ($f['name'] === '') $err[] = 'Branch name is required.';
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email looks wrong.';
    if (!$pid && ($lim = biz_limit($a, 'max_branches')) > 0 && count(biz_branches($aid, false)) >= $lim) $err[] = "Your plan allows $lim branch" . ($lim === 1 ? '' : 'es') . '. Upgrade under Subscription to add more.';
    if (!$err) {
        if ($pid) $pdo->prepare('UPDATE biz_branches SET name=?, location=?, phone=?, email=?, manager_user_id=? WHERE id=? AND account_id=?')->execute([...array_values($f), $mgr ?: null, $pid, $aid]);
        else $pdo->prepare('INSERT INTO biz_branches (account_id, name, location, phone, email, manager_user_id, is_active, created_at) VALUES (?,?,?,?,?,?,1,?)')->execute([$aid, ...array_values($f), $mgr ?: null, gmdate('Y-m-d H:i:s')]);
        biz_audit('branch_saved', $f['name']); flash('Branch saved.'); redirect('branches.php');
    }
    $e = $f + ['id' => $pid, 'manager_user_id' => $mgr];
}
$rows = biz_branches($aid, false); $cnt = [];
$s = $pdo->prepare('SELECT branch_id, COUNT(*) c FROM biz_user_ext WHERE account_id = ? AND branch_id IS NOT NULL GROUP BY branch_id'); $s->execute([$aid]); foreach ($s as $r) $cnt[$r['branch_id']] = (int) $r['c'];
biz_head('Branches', 'branch'); ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<p class="muted">Add each shop, office or location. Assign team members to a branch under Team, and choose the branch when you make a document. Reports can be viewed for the whole business or one branch.</p>
<?php if ($can): ?><div class="card"><h2><?= $e ? 'Edit branch' : 'Add branch' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
<label>Branch name<input name="name" required maxlength="190" value="<?= h((string) ($e['name'] ?? '')) ?>"></label><label>Location<input name="location" maxlength="500" value="<?= h((string) ($e['location'] ?? '')) ?>"></label>
<div class="two"><label>Phone<input name="phone" maxlength="60" value="<?= h((string) ($e['phone'] ?? '')) ?>"></label><label>Email<input name="email" type="email" maxlength="190" value="<?= h((string) ($e['email'] ?? '')) ?>"></label></div>
<label>Branch administrator<select name="manager_user_id"><option value="">None</option><?php foreach ($users as $x): ?><option value="<?= (int) $x['id'] ?>"<?= (int) ($e['manager_user_id'] ?? 0) === (int) $x['id'] ? ' selected' : '' ?>><?= h($x['full_name']) ?></option><?php endforeach; ?></select></label>
<div class="actions"><?php if ($e): ?><a class="btn ghost" href="branches.php">Cancel</a><?php endif; ?><button class="btn">Save</button></div></form></div><?php endif; ?>
<div class="card flush"><?php if (!$rows): ?><p class="muted pad">No branches yet.</p><?php endif; ?><div class="list"><?php foreach ($rows as $b): ?>
<div class="row<?= $b['is_active'] ? '' : ' dim' ?>"><div><b><?= h($b['name']) ?></b><small><?= h((string) $b['location']) ?> · <?= (int) ($cnt[$b['id']] ?? 0) ?> team member(s)</small></div><div class="r"><a class="btn sm ghost" href="reports.php?branch=<?= (int) $b['id'] ?>">Report</a><?php if ($can): ?><a class="btn sm ghost" href="branches.php?edit=<?= (int) $b['id'] ?>">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn sm ghost" name="action" value="toggle"><?= $b['is_active'] ? 'Disable' : 'Enable' ?></button></form><?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php biz_foot();
