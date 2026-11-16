<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
$admin = require_login(); $pdo = db();
$hasDocs = can('doc.view', $admin);
[$from, $to] = period_range('month');
page_head('Dashboard', $admin);
$reqNew = (int) $pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'new'")->fetchColumn();
echo '<div class="hdr"><div><h1>Dashboard</h1><span class="muted">Welcome, ' . h($admin['full_name']) . '</span></div><span class="spacer"></span>';
if (can('doc.create', $admin)) foreach (['quotation', 'invoice', 'receipt'] as $k) echo '<a class="btn' . ($k === 'invoice' ? '' : ' ghost') . '" href="doc_edit.php?type=' . $k . '">New ' . strtolower(DOC_TYPES[$k]['label']) . '</a>';
echo '</div>';
if (!$hasDocs) { echo '<div class="card"><h2>Website requests</h2><p>' . $reqNew . ' new request' . ($reqNew === 1 ? '' : 's') . ' waiting. <a href="index.php">Open requests</a></p><p class="muted">Your account does not have access to documents yet. A super admin can grant it under User management.</p></div>'; page_foot(); exit; }
// month activity by type
$st = $pdo->prepare("SELECT doc_type, status, COUNT(*) c, COALESCE(SUM(total),0) t, currency FROM documents WHERE doc_date >= ? AND doc_date <= ? GROUP BY doc_type, status, currency"); $st->execute([$from, $to]);
$byType = array_fill_keys(array_keys(DOC_TYPES), ['n' => 0, 'posted' => 0, 'draft' => 0, 'cancelled' => 0]); $inv = []; $rec = [];
foreach ($st->fetchAll() as $r) { $k = $r['doc_type']; $byType[$k]['n'] += (int) $r['c']; $byType[$k][$r['status']] += (int) $r['c']; if ($r['status'] === 'posted') { if ($k === 'invoice') $inv[$r['currency']] = ($inv[$r['currency']] ?? 0) + db_cents($r['t']); if ($k === 'receipt') $rec[$r['currency']] = ($rec[$r['currency']] ?? 0) + db_cents($r['t']); } }
$o1 = $pdo->query("SELECT currency, SUM(total) t FROM documents WHERE doc_type = 'invoice' AND status = 'posted' GROUP BY currency")->fetchAll();
$o2 = $pdo->query("SELECT r.currency, SUM(r.total) t FROM documents r JOIN documents i ON i.id = r.parent_id WHERE r.doc_type = 'receipt' AND r.status = 'posted' AND i.status = 'posted' AND i.doc_type = 'invoice' GROUP BY r.currency")->fetchAll();
$out = []; foreach ($o1 as $r) $out[$r['currency']] = db_cents($r['t']); foreach ($o2 as $r) $out[$r['currency']] = ($out[$r['currency']] ?? 0) - db_cents($r['t']);
$out = array_filter($out, fn($v) => $v !== 0);
$cs = function (array $m) { if (!$m) return '0'; ksort($m); $o = []; foreach ($m as $cu => $v) $o[] = money($v, $cu) . ' ' . $cu; return implode(' / ', $o); };
$drafts = (int) $pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'draft'")->fetchColumn();
$cancelled = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE status = 'cancelled' AND doc_date >= ? AND doc_date <= ?"); $cancelled->execute([$from, $to]);
$max = max(1, max(array_map(fn($x) => $x['n'], $byType)));
?>
<div class="stats">
  <div class="stat"><b style="font-size:20px"><?= h($cs($inv)) ?></b><span>Invoiced this month</span></div>
  <div class="stat"><b style="font-size:20px"><?= h($cs($rec)) ?></b><span>Receipts this month</span></div>
  <div class="stat"><b style="font-size:20px"><?= h($cs($out)) ?></b><span>Outstanding on posted invoices</span></div>
  <div class="stat"><b><?= $drafts ?></b><span>Drafts waiting to be posted</span></div>
  <div class="stat"><b><?= (int) $cancelled->fetchColumn() ?></b><span>Cancelled this month</span></div>
  <div class="stat"><b><?= $reqNew ?></b><span><a href="index.php">New website requests</a></span></div>
</div>
<div class="cols2"><div class="card"><h2>Documents this month</h2><div class="bars"><?php foreach (DOC_TYPES as $k => $t): $x = $byType[$k]; ?>
  <div class="bar"><a href="docs.php?type=<?= $k ?>&from=<?= $from ?>&to=<?= $to ?>"><?= h($t['plural']) ?></a><span style="display:block;background:#EEF3FA;border-radius:6px"><i style="width:<?= max($x['n'] ? 3 : 0, (int) round($x['n'] / $max * 100)) ?>%"></i></span><span class="num"><?= $x['n'] ?> <small class="muted">(<?= $x['posted'] ?> posted)</small></span></div><?php endforeach; ?></div></div>
<div class="card"><h2>Recent documents</h2><ul class="timeline"><?php foreach (list_documents($pdo, [], 1, 8)['rows'] as $d): ?>
  <li><a href="doc.php?id=<?= (int) $d['id'] ?>"><b><?= h($d['doc_no']) ?></b></a> <span class="pill <?= h($d['status']) ?>"><?= h($d['status']) ?></span><small><?= h($d['customer_name']) ?> &middot; <?= money(db_cents($d['total']), $d['currency']) ?> <?= h($d['currency']) ?> &middot; <?= h(eat($d['created_at'])) ?></small></li><?php endforeach; ?></ul></div></div>
<?php page_foot();
