<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); biz_need_module('items'); $pdo = db(); $aid = (int) $a['id']; $can = $a['_sub']['ok'] && bcan('items.manage'); $err = [];
$id = (int) ($_GET['edit'] ?? 0); $e = null;
if ($id) { $s = $pdo->prepare('SELECT * FROM biz_items WHERE id = ? AND account_id = ?'); $s->execute([$id, $aid]); $e = $s->fetch() ?: null; }
$cats = []; try { $q = $pdo->prepare('SELECT * FROM biz_categories WHERE account_id = ? ORDER BY name'); $q->execute([$aid]); $cats = $q->fetchAll(); } catch (Throwable $x) {}
$meta = ['kind' => 'service', 'category_id' => 0, 'stock_qty' => '']; if ($e) { try { $q = $pdo->prepare('SELECT * FROM biz_item_meta WHERE item_id = ?'); $q->execute([$id]); $meta = array_merge($meta, $q->fetch() ?: []); } catch (Throwable $x) {} }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can) {
    if (($_POST['action'] ?? '') === 'addcat') { $cn = mb_substr(trim((string) ($_POST['cat_name'] ?? '')), 0, 120); if ($cn !== '') { $pdo->prepare('INSERT INTO biz_categories (account_id, name) VALUES (?,?)')->execute([$aid, $cn]); flash('Category added.'); } redirect('items.php'); }
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
        $itemId = $pid ?: (int) $pdo->lastInsertId(); $kind = ($_POST['kind'] ?? '') === 'product' ? 'product' : 'service'; $catId = (int) ($_POST['category_id'] ?? 0); $okc = false; foreach ($cats as $c) if ((int) $c['id'] === $catId) $okc = true;
        $stock = $kind === 'product' && trim((string) ($_POST['stock_qty'] ?? '')) !== '' && is_numeric($_POST['stock_qty']) ? (string) $_POST['stock_qty'] : null;
        try { $pdo->prepare('DELETE FROM biz_item_meta WHERE item_id = ? AND account_id = ?')->execute([$itemId, $aid]); $pdo->prepare('INSERT INTO biz_item_meta (item_id, account_id, category_id, kind, stock_qty) VALUES (?,?,?,?,?)')->execute([$itemId, $aid, $okc ? $catId : null, $kind, $stock]); } catch (Throwable $x) {}
        biz_audit('item_saved', $name); flash('Item saved.'); redirect('items.php');
    }
    $e = ['id' => $pid, 'name' => $name, 'unit' => $unit, 'unit_price' => $price, 'tax_rate' => $tax];
}
$s = $pdo->prepare('SELECT * FROM biz_items WHERE account_id = ? ORDER BY is_active DESC, name LIMIT 500'); $s->execute([$aid]); $rows = $s->fetchAll();
biz_head('Items and services', 'items'); ?>
<?php if ($err): ?><div class="flash bad"><?php foreach ($err as $x): ?><div><?= h($x) ?></div><?php endforeach; ?></div><?php endif; ?>
<p class="muted">Save things you sell often, such as a daily car hire rate or an airport transfer. They show up when you type in a document line.</p>
<?php if ($can): ?><div class="card"><h2><?= $e ? 'Edit item' : 'Add item' ?></h2><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($e['id'] ?? 0) ?>">
<label>Name<input name="name" required maxlength="190" value="<?= h((string) ($e['name'] ?? '')) ?>" placeholder="Airport transfer"></label>
<div class="two"><label>Type<select name="kind"><option value="service"<?= $meta['kind'] === 'service' ? ' selected' : '' ?>>Service</option><option value="product"<?= $meta['kind'] === 'product' ? ' selected' : '' ?>>Product</option></select></label><label>Category<select name="category_id"><option value="">None</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= (int) $meta['category_id'] === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></label><label>Stock on hand (products)<input name="stock_qty" inputmode="decimal" value="<?= h(trim_num($meta['stock_qty'] ?? '')) ?>"></label><label>Unit<input name="unit" maxlength="30" value="<?= h((string) ($e['unit'] ?? '')) ?>" placeholder="trip, day, km"></label><label>Price<input name="unit_price" inputmode="decimal" value="<?= h((string) ($e['unit_price'] ?? '')) ?>"></label><label>Tax %<input name="tax_rate" inputmode="decimal" value="<?= h((string) ($e['tax_rate'] ?? '0')) ?>"></label></div>
<div class="actions"><?php if ($e): ?><a class="btn ghost" href="items.php">Cancel</a><?php endif; ?><button class="btn">Save</button></div></form></div><?php endif; ?>
<?php if ($can): ?><form method="post" class="bar"><?= csrf_field() ?><input type="hidden" name="action" value="addcat"><input name="cat_name" placeholder="New category" maxlength="120"><button class="btn sm ghost">Add category</button></form><?php endif; ?>
<div class="card flush"><?php if (!$rows): ?><p class="muted pad">No items yet.</p><?php endif; ?><div class="list"><?php foreach ($rows as $it): ?>
<div class="row<?= $it['is_active'] ? '' : ' dim' ?>"><div><b><?= h($it['name']) ?></b><small><?= h(dmoney(to_cents($it['unit_price']), $a['currency'])) ?><?= $it['unit'] ? ' / ' . h($it['unit']) : '' ?><?= (float) $it['tax_rate'] > 0 ? ' · tax ' . h(trim_num($it['tax_rate'])) . '%' : '' ?></small></div>
<div class="r"><?php if ($can): ?><a class="btn sm ghost" href="items.php?edit=<?= (int) $it['id'] ?>">Edit</a><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $it['id'] ?>"><button class="btn sm ghost" name="action" value="toggle"><?= $it['is_active'] ? 'Hide' : 'Show' ?></button></form><?php endif; ?></div></div><?php endforeach; ?></div></div>
<?php biz_foot();
