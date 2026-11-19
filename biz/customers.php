<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $can = $a['_sub']['ok']; $err = [];
$id = (int) ($_GET['edit'] ?? 0); $e = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM biz_customers WHERE id = ? AND account_id = ?'); $s->execute([$id, $aid]); $e = $s->fetch() ?: null; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    $f = []; foreach (['name' => 190, 'contact_person' => 190, 'phone' => 60, 'email' => 190, 'address' => 500, 'tin' => 20] as $k => $n) $f[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $n);
    $pid = (int) ($_POST['id'] ?? 0);
    if ($f['name'] === '') $err[] = 'Name is required.'; if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err[] = 'Email looks wrong.';
    if (!$err) {
        if ($pid) { $pdo->prepare('UPDATE biz_customers SET name=?, contact_person=?, phone=?, email=?, address=?, tin=? WHERE id=? AND account_id=?')->execute([...array_values($f), $pid, $aid]); flash('Customer updated.'); }
        else { $pdo->prepare('INSERT INTO biz_customers (account_id, name, contact_person, phone, email, address, tin, created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$aid, ...array_values($f), gmdate('Y-m-d H:i:s')]); flash('Customer added.'); }
        redirect('customers.php');
    }
    $e = $f + ['id' => $pid];
}
$q = trim((string) ($_GET['q'] ?? '')); $sql = 'SELECT * FROM biz_customers WHERE account_id = ?'; $p = [$aid];
if ($q !== '') { $sql .= ' AND (name LIKE ? OR phone LIKE ?)'; $p[] = "%$q%"; $p[] = "%$q%"; }
$s = $pdo->prepare($sql . ' ORDER BY name LIMIT 300'); $s->execute($p); $rows = $s->fetchAll();
biz_head('Customers', 'cust'); ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($can): ?><div class="card"><h2><?= $e ? 'Edit customer' : 'Add customer' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
<label>Name<input name="name" required maxlength="190" value="<?= h((string) ($e['name'] ?? '')) ?>"></label>
<div class="two"><label>Phone<input name="phone" maxlength="60" value="<?= h((string) ($e['phone'] ?? '')) ?>"></label><label>Email<input name="email" type="email" maxlength="190" value="<?= h((string) ($e['email'] ?? '')) ?>"></label>
<label>Contact person<input name="contact_person" maxlength="190" value="<?= h((string) ($e['contact_person'] ?? '')) ?>"></label><label>TIN<input name="tin" maxlength="20" value="<?= h((string) ($e['tin'] ?? '')) ?>"></label></div>
<label>Address<input name="address" maxlength="500" value="<?= h((string) ($e['address'] ?? '')) ?>"></label>
<div class="actions"><?php if ($e): ?><a class="btn ghost" href="customers.php">Cancel</a><?php endif; ?><button class="btn">Save</button></div></form></div><?php endif; ?>
<form class="bar" method="get"><input name="q" placeholder="Search customers" value="<?= h($q) ?>"><button class="btn sm ghost">Search</button></form>
<div class="card flush"><?php if (!$rows): ?><p class="muted pad">No customers yet.</p><?php endif; ?><div class="list"><?php foreach ($rows as $c): ?>
<div class="row"><div><b><?= h($c['name']) ?></b><small><?= h(trim($c['phone'] . ' ' . $c['email'])) ?></small></div><div class="r"><?php if ($can): ?><a class="btn sm ghost" href="customers.php?edit=<?= (int) $c['id'] ?>">Edit</a><?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php biz_foot();
