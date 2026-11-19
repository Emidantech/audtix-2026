<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $can = $a['_sub']['ok']; $err = [];
$id = (int) ($_GET['edit'] ?? 0); $e = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM biz_items WHERE id = ? AND account_id = ?'); $s->execute([$id, $aid]); $e = $s->fetch() ?: null; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    $pid = (int) ($_POST['id'] ?? 0); $act = (string) ($_POST['action'] ?? 'save');
    if ($act === 'toggle' && $pid) { $pdo->prepare('UPDATE biz_items SET is_active = 1 - is_active WHERE id = ? AND account_id = ?')->execute([$pid, $aid]); redirect('items.php'); }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 190); $unit = mb_substr(trim((string) ($_POST['unit'] ?? '')), 0, 30);
    $price = (string) ($_POST['unit_price'] ?? '0'); $tax = (string) ($_POST['tax_rate'] ?? '0');
    if ($name === '') $err[] = 'Name is required.';
    if (!is_numeric(str_replace(',', '', $price)) || (float) str_replace(',', '', $price) < 0) $err[] = 'Price must be a number.';
    if (!is_numeric($tax) || $tax < 0 || $tax > 100) $err[] = 'Tax must be between 0 and 100.';
    if (!$err) {
        $pr = number_format((float) str_replace(',', '', $price), 2, '.', ''); $tx = number_format((float) $tax, 2, '.', '');
        if ($pid) $pdo->prepare('UPDATE biz_items SET name=?, unit=?, unit_price=?, tax_rate=? WHERE id=? AND account_id=?')->execute([$name, $unit, $pr, $tx, $pid, $aid]);
        else $pdo->prepare('INSERT INTO biz_items (account_id, name, unit, unit_price, tax_rate, is_active) VALUES (?,?,?,?,?,1)')->execute([$aid, $name, $unit, $pr, $tx]);
        flash('Item saved.'); redirect('items.php');
    }
    $e = ['id' => $pid, 'name' => $name, 'unit' => $unit, 'unit_price' => $price, 'tax_rate' => $tax];
}
$s = $pdo->prepare('SELECT * FROM biz_items WHERE account_id = ? ORDER BY is_active DESC, name LIMIT 500'); $s->execute([$aid]); $rows = $s->fetchAll();
biz_head('Items and services', 'items'); ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<p class="muted">Save things you sell often, such as a daily car hire rate or an airport transfer. They show up when you type in a document line.</p>
<?php if ($can): ?><div class="card"><h2><?= $e ? 'Edit item' : 'Add item' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
<label>Name<input name="name" required maxlength="190" value="<?= h((string) ($e['name'] ?? '')) ?>" placeholder="Airport transfer"></label>
<div class="two"><label>Unit<input name="unit" maxlength="30" value="<?= h((string) ($e['unit'] ?? '')) ?>" placeholder="trip, day, km"></label><label>Price<input name="unit_price" inputmode="decimal" value="<?= h((string) ($e['unit_price'] ?? '')) ?>"></label><label>Tax %<input name="tax_rate" inputmode="decimal" value="<?= h((string) ($e['tax_rate'] ?? '0')) ?>"></label></div>
<div class="actions"><?php if ($e): ?><a class="btn ghost" href="items.php">Cancel</a><?php endif; ?><button class="btn">Save</button></div></form></div><?php endif; ?>
<div class="card flush"><?php if (!$rows): ?><p class="muted pad">No items yet.</p><?php endif; ?><div class="list"><?php foreach ($rows as $it): ?>
<div class="row<?= $it['is_active'] ? '' : ' dim' ?>"><div><b><?= h($it['name']) ?></b><small><?= h(dmoney(to_cents($it['unit_price']), $a['currency'])) ?><?= $it['unit'] ? ' / ' . h($it['unit']) : '' ?><?= (float) $it['tax_rate'] > 0 ? ' · tax ' . h(trim_num($it['tax_rate'])) . '%' : '' ?></small></div>
<div class="r"><?php if ($can): ?><a class="btn sm ghost" href="items.php?edit=<?= (int) $it['id'] ?>">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn sm ghost" name="action" value="toggle"><?= $it['is_active'] ? 'Hide' : 'Show' ?></button></form><?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php biz_foot();
