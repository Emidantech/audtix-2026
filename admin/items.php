<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_login(); $pdo = db();
$canManage = can('product.manage', $admin);
if (!$canManage && !can('doc.view', $admin)) require_perm('doc.view');
$errors = []; $in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) require_perm('product.manage');
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'toggle' && $id) { $pdo->prepare('UPDATE items SET is_active = 1 - is_active, updated_at = ? WHERE id = ?')->execute([now(), $id]); log_activity((int) $admin['id'], 'item_toggle', (string) $id); flash('Updated.'); redirect('items.php'); }
    $in = ['item_type' => ($_POST['item_type'] ?? '') === 'service' ? 'service' : 'product', 'name' => t($_POST, 'name', 190), 'description' => t($_POST, 'description', 1000), 'category' => t($_POST, 'category', 80), 'unit' => t($_POST, 'unit', 30), 'unit_price' => t($_POST, 'unit_price', 20), 'tax_rate' => t($_POST, 'tax_rate', 6)];
    if (mb_strlen($in['name']) < 2) $errors['name'] = 'Name is required.';
    $pc = $in['unit_price'] === '' ? 0 : to_cents($in['unit_price']); if ($pc === null) $errors['unit_price'] = 'Enter a valid price.';
    $tr = $in['tax_rate'] === '' ? '0' : $in['tax_rate']; if (!preg_match('/^\d{1,3}(\.\d{1,2})?$/', $tr) || (float) $tr > 100) $errors['tax_rate'] = 'Tax % must be between 0 and 100.';
    if (!$errors) {
        if ($id) { $pdo->prepare('UPDATE items SET item_type=?, name=?, description=?, category=?, unit=?, unit_price=?, tax_rate=?, updated_at=? WHERE id=?')->execute([$in['item_type'], $in['name'], $in['description'] ?: null, $in['category'] ?: null, $in['unit'] ?: null, dec($pc), $tr, now(), $id]); log_activity((int) $admin['id'], 'item_updated', (string) $id, $in['name']); }
        else { $pdo->prepare('INSERT INTO items (item_type, name, description, category, unit, unit_price, tax_rate, is_active, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,1,?,?,?)')->execute([$in['item_type'], $in['name'], $in['description'] ?: null, $in['category'] ?: null, $in['unit'] ?: null, dec($pc), $tr, $admin['id'], now(), now()]); log_activity((int) $admin['id'], 'item_created', (string) $pdo->lastInsertId(), $in['name']); }
        flash('Saved.'); redirect('items.php');
    }
    $_GET['edit'] = $id ?: 'new';
}
$edit = $_GET['edit'] ?? null;
$cats = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
if ($edit !== null && $canManage) {
    $it = ['id' => 0, 'item_type' => 'product', 'name' => '', 'description' => '', 'category' => '', 'unit' => '', 'unit_price' => '', 'tax_rate' => setting('default_vat', '0'), 'is_active' => 1];
    if ($edit !== 'new') { $s = $pdo->prepare('SELECT * FROM items WHERE id = ?'); $s->execute([(int) $edit]); $it = $s->fetch() ?: $it; }
    $it = array_merge($it, $in); page_head('Product or service', $admin); ?>
<p><a href="items.php">&larr; Back</a></p><h1><?= $it['id'] ? 'Edit' : 'Add' ?> product or service</h1>
<div class="card"><form method="post" class="stack" style="max-width:680px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
  <label>Type<select name="item_type"><option value="product"<?= $it['item_type'] === 'product' ? ' selected' : '' ?>>Product</option><option value="service"<?= $it['item_type'] === 'service' ? ' selected' : '' ?>>Service</option></select></label>
  <label>Name *<input name="name" required maxlength="190" value="<?= h($it['name']) ?>"><?= isset($errors['name']) ? '<span class="err">' . h($errors['name']) . '</span>' : '' ?></label>
  <label>Description (appears on documents)<textarea name="description" maxlength="1000" style="min-height:70px"><?= h($it['description']) ?></textarea></label>
  <div class="cols3"><label>Category<input name="category" list="cats" maxlength="80" value="<?= h($it['category']) ?>"><datalist id="cats"><?php foreach ($cats as $c) echo '<option value="' . h($c) . '">'; ?></datalist></label>
  <label>Unit of measure<input name="unit" maxlength="30" placeholder="pcs, hrs, month" value="<?= h($it['unit']) ?>"></label>
  <label>Unit price<input name="unit_price" inputmode="decimal" value="<?= h($it['unit_price'] === '' ? '' : (is_numeric($it['unit_price']) ? rtrim(rtrim(number_format((float) $it['unit_price'], 2, '.', ''), '0'), '.') : $it['unit_price'])) ?>"><?= isset($errors['unit_price']) ? '<span class="err">' . h($errors['unit_price']) . '</span>' : '' ?></label>
  <label>Tax % (VAT)<input name="tax_rate" inputmode="decimal" value="<?= h(is_numeric($it['tax_rate']) ? rtrim(rtrim(number_format((float) $it['tax_rate'], 2, '.', ''), '0'), '.') : $it['tax_rate']) ?>"><?= isset($errors['tax_rate']) ? '<span class="err">' . h($errors['tax_rate']) . '</span>' : '' ?></label></div>
  <div class="row"><button class="btn">Save</button><a class="btn ghost" href="items.php">Cancel</a></div></form></div>
<?php page_foot(); exit; }
$q = trim((string) ($_GET['q'] ?? '')); $type = $_GET['type'] ?? ''; $cat = $_GET['category'] ?? ''; $show = ($_GET['show'] ?? '') === 'off' ? 0 : 1;
$w = ['is_active = ?']; $a = [$show]; if (in_array($type, ['product', 'service'], true)) { $w[] = 'item_type = ?'; $a[] = $type; } if ($cat !== '') { $w[] = 'category = ?'; $a[] = $cat; }
if ($q !== '') { $w[] = '(name LIKE ? OR description LIKE ?)'; $l = '%' . $q . '%'; array_push($a, $l, $l); }
$s = $pdo->prepare('SELECT * FROM items WHERE ' . implode(' AND ', $w) . ' ORDER BY name LIMIT 300'); $s->execute($a); $rows = $s->fetchAll();
page_head('Products & Services', $admin); ?>
<div class="hdr"><h1>Products &amp; Services</h1><span class="spacer"></span><?php if ($canManage): ?><a class="btn" href="?edit=new">Add product or service</a><?php endif; ?></div>
<p class="muted">The central price list. Picking one on a document fills in its description, unit, price and tax.</p>
<form class="filters" method="get"><label>Search<input name="q" value="<?= h($q) ?>"></label><label>Type<select name="type"><option value="">All</option><option value="product"<?= $type === 'product' ? ' selected' : '' ?>>Products</option><option value="service"<?= $type === 'service' ? ' selected' : '' ?>>Services</option></select></label>
<label>Category<select name="category"><option value="">All</option><?php foreach ($cats as $c) echo '<option' . ($cat === $c ? ' selected' : '') . '>' . h($c) . '</option>'; ?></select></label><label>Show<select name="show"><option value="">Active</option><option value="off"<?= !$show ? ' selected' : '' ?>>Switched off</option></select></label><button class="btn">Filter</button></form>
<div class="card tablewrap" style="padding:0"><table><thead><tr><th>Name</th><th>Type</th><th>Category</th><th>Unit</th><th class="num">Unit price</th><th class="num">Tax %</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><b><?= h($r['name']) ?></b><br><span class="muted"><?= h(mb_strimwidth((string) $r['description'], 0, 90, '…')) ?></span></td><td><?= h(ucfirst($r['item_type'])) ?></td><td><?= h($r['category']) ?></td><td><?= h($r['unit']) ?></td><td class="num"><?= money(db_cents($r['unit_price'])) ?></td><td class="num"><?= h(rtrim(rtrim(number_format((float) $r['tax_rate'], 2), '0'), '.')) ?>%</td>
<td style="white-space:nowrap"><?php if ($canManage): ?><a class="btn sm ghost" href="?edit=<?= (int) $r['id'] ?>">Edit</a> <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn sm ghost"><?= $show ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr><?php endforeach; if (!$rows) echo '<tr><td colspan="7" class="muted" style="padding:24px">Nothing found.</td></tr>'; ?></tbody></table></div>
<?php page_foot();
