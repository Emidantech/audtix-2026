<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_perm('doc.view'); $pdo = db();
$type = $_GET['type'] ?? ''; if (!isset(DOC_TYPES[$type])) $type = '';
$T = $type ? DOC_TYPES[$type] : null;
$f = ['type' => $type, 'status' => $_GET['status'] ?? '', 'q' => trim((string) ($_GET['q'] ?? '')), 'customer_id' => (int) ($_GET['customer_id'] ?? 0), 'created_by' => (int) ($_GET['created_by'] ?? 0), 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''];
$res = list_documents($pdo, $f, max(1, (int) ($_GET['page'] ?? 1)));
$admins = $pdo->query('SELECT id, full_name FROM admins ORDER BY full_name')->fetchAll();
$cust = $f['customer_id'] ? $pdo->query('SELECT id, name FROM customers WHERE id = ' . (int) $f['customer_id'])->fetch() : null;
page_head($T ? $T['plural'] : 'All documents', $admin);
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['type' => $type, 'status' => $f['status'], 'q' => $f['q'], 'customer_id' => $f['customer_id'] ?: '', 'created_by' => $f['created_by'] ?: '', 'from' => $f['from'], 'to' => $f['to']], $o), fn($v) => $v !== '' && $v !== null));
?>
<div class="hdr"><h1><?= h($T ? $T['plural'] : 'All documents') ?></h1><span class="spacer"></span>
<?php if (can('doc.create', $admin)): if ($type): ?><a class="btn" href="doc_edit.php?type=<?= h($type) ?>">New <?= h(strtolower($T['label'])) ?></a><?php else: foreach (DOC_TYPES as $k => $t) echo '<a class="btn sm ghost" href="doc_edit.php?type=' . $k . '">New ' . h(strtolower($t['label'])) . '</a> '; endif; endif; ?></div>
<div class="tabs"><a href="docs.php"<?= !$type ? ' class="on"' : '' ?>>All</a><?php foreach (DOC_TYPES as $k => $t) echo '<a href="docs.php?type=' . $k . '"' . ($type === $k ? ' class="on"' : '') . '>' . h($t['plural']) . '</a>'; ?></div>
<form class="filters" method="get"><input type="hidden" name="type" value="<?= h($type) ?>">
  <label>Search<input name="q" value="<?= h($f['q']) ?>" placeholder="Number, customer, reference"></label>
  <label>Status<select name="status"><option value="">All</option><?php foreach (['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled'] as $k => $v) echo '<option value="' . $k . '"' . ($f['status'] === $k ? ' selected' : '') . '>' . $v . '</option>'; ?></select></label>
  <label>Created by<select name="created_by"><option value="">Anyone</option><?php foreach ($admins as $a) echo '<option value="' . (int) $a['id'] . '"' . ($f['created_by'] === (int) $a['id'] ? ' selected' : '') . '>' . h($a['full_name']) . '</option>'; ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($f['from']) ?>"></label><label>To<input type="date" name="to" value="<?= h($f['to']) ?>"></label>
  <?php if ($cust): ?><input type="hidden" name="customer_id" value="<?= (int) $cust['id'] ?>"><span class="pill">Customer: <?= h($cust['name']) ?></span><?php endif; ?>
  <button class="btn">Filter</button><a class="btn ghost" href="docs.php<?= $type ? '?type=' . h($type) : '' ?>">Clear</a></form>
<div class="card tablewrap" style="padding:0"><table><thead><tr><th>Number</th><?php if (!$type) echo '<th>Type</th>'; ?><th>Customer</th><th>Date</th><th>Status</th><th class="num">Total</th><th class="num">Balance</th><th>By</th></tr></thead><tbody>
<?php foreach ($res['rows'] as $d): $bal = $d['doc_type'] === 'invoice' && $d['status'] === 'posted' ? db_cents($d['total']) - db_cents($d['paid']) : null; ?>
<tr><td class="mono"><a href="doc.php?id=<?= (int) $d['id'] ?>"><?= h($d['doc_no']) ?></a></td><?php if (!$type) echo '<td>' . h(DOC_TYPES[$d['doc_type']]['label']) . '</td>'; ?>
<td><b><?= h($d['customer_name']) ?></b><?= $d['reference_text'] ? '<br><span class="muted">' . h($d['reference_text']) . '</span>' : '' ?></td><td><?= h(fmt_date($d['doc_date'])) ?></td><td><span class="pill <?= h($d['status']) ?>"><?= h(ucfirst($d['status'])) ?></span></td>
<td class="num"><?= money(db_cents($d['total']), $d['currency']) ?> <small class="muted"><?= h($d['currency']) ?></small></td><td class="num"><?= $bal === null ? '' : ($bal > 0 ? '<b>' . money($bal, $d['currency']) . '</b>' : '<span class="pill good">Paid</span>') ?></td><td><?= h($d['creator']) ?></td></tr>
<?php endforeach; if (!$res['rows']) echo '<tr><td colspan="8" class="muted" style="padding:26px">No documents match.</td></tr>'; ?></tbody></table></div>
<div class="pager"><?= $res['total'] ?> document<?= $res['total'] === 1 ? '' : 's' ?> &middot; page <?= $res['page'] ?> of <?= $res['pages'] ?>
<?php if ($res['page'] > 1) echo '<a class="btn sm ghost" href="' . h($qs(['page' => $res['page'] - 1])) . '">Previous</a>'; if ($res['page'] < $res['pages']) echo '<a class="btn sm ghost" href="' . h($qs(['page' => $res['page'] + 1])) . '">Next</a>'; ?></div>
<?php page_foot();
