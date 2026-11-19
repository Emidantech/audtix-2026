<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $aid = (int) $a['id']; $cur = $a['currency']; $m0 = gmdate('Y-m-01');
[$bq, $bp] = biz_branch_sql('id');
$sum = function (string $sql, array $x = []) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($x); return db_cents($s->fetchColumn()); };
$inv = $sum("SELECT COALESCE(SUM(total),0) FROM biz_documents WHERE account_id = ? AND doc_type = 'invoice' AND status = 'posted' AND doc_date >= ?" . $bq, [$aid, $m0, ...$bp]);
$rec = $sum("SELECT COALESCE(SUM(total),0) FROM biz_documents WHERE account_id = ? AND doc_type = 'receipt' AND status = 'posted' AND doc_date >= ?" . $bq, [$aid, $m0, ...$bp]);
$open = $sum("SELECT COALESCE(SUM(total),0) FROM biz_documents WHERE account_id = ? AND doc_type = 'invoice' AND status = 'posted'" . $bq, [$aid, ...$bp])
      - $sum("SELECT COALESCE(SUM(r.total),0) FROM biz_documents r WHERE r.account_id = ? AND r.doc_type = 'receipt' AND r.status = 'posted' AND r.parent_id IN (SELECT id FROM biz_documents WHERE account_id = ? AND doc_type = 'invoice' AND status = 'posted')" . $bq, [$aid, $aid, ...$bp]);
$recent = $pdo->prepare('SELECT * FROM biz_documents WHERE account_id = ?' . $bq . ' ORDER BY id DESC LIMIT 8'); $recent->execute([$aid, ...$bp]); $recent = $recent->fetchAll();
biz_head('Home', 'home'); $can = $a['_sub']['ok']; ?>
<div class="quick"><?php foreach (BIZ_TYPES as $t): if (!bcan('docs.create')) continue; ?><a class="q <?= $can ? '' : 'off' ?>" href="<?= $can ? 'doc_edit.php?type=' . $t : 'billing.php' ?>"><span>+</span><?= h(DOC_TYPES[$t]['label']) ?></a><?php endforeach; ?></div>
<div class="stats">
  <div class="stat"><small>Invoiced this month</small><b><?= h(dmoney($inv, $cur)) ?></b></div>
  <div class="stat"><small>Received this month</small><b><?= h(dmoney($rec, $cur)) ?></b></div>
  <div class="stat warn"><small>Unpaid invoices</small><b><?= h(dmoney(max(0, $open), $cur)) ?></b></div>
</div>
<div class="card"><div class="ch"><h2>Latest documents</h2><a href="docs.php">All documents</a></div>
<?php if (!$recent): ?><p class="muted">Nothing yet. Tap a button above to make your first document.</p><?php else: ?><div class="list"><?php foreach ($recent as $d): ?>
  <a class="row" href="doc.php?id=<?= (int) $d['id'] ?>"><div><b><?= h($d['customer_name']) ?></b><small><?= h(DOC_TYPES[$d['doc_type']]['label']) ?> &middot; <span class="no"><?= h($d['doc_no']) ?></span> &middot; <?= h(fmt_date($d['doc_date'])) ?></small></div><div class="r"><b><?= h(dmoney(db_cents($d['total']), $d['currency'])) ?></b><span class="pill <?= h($d['status']) ?>"><?= h(ucfirst($d['status'])) ?></span></div></a>
<?php endforeach; ?></div><?php endif; ?></div>
<?php biz_foot();
