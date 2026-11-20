<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); biz_need('docs.view'); biz_need_module('docs'); $a = account(); $pdo = db(); $aid = (int) $a['id'];
$type = in_array($_GET['type'] ?? '', BIZ_TYPES, true) ? $_GET['type'] : ''; $st = in_array($_GET['status'] ?? '', ['draft', 'posted', 'cancelled'], true) ? $_GET['status'] : ''; $q = trim((string) ($_GET['q'] ?? '')); $page = max(1, (int) ($_GET['page'] ?? 1)); $per = 25;
$w = ['account_id = ?']; $x = [$aid]; if ($type) { $w[] = 'doc_type = ?'; $x[] = $type; } if ($st) { $w[] = 'status = ?'; $x[] = $st; } if ($q !== '') { $w[] = '(doc_no LIKE ? OR customer_name LIKE ?)'; $x[] = "%$q%"; $x[] = "%$q%"; }
[$bq, $bp] = biz_branch_sql('id'); if ($bq !== '') { $w[] = substr($bq, 5); $x = array_merge($x, $bp); }
$bid = (int) ($_GET['branch'] ?? 0); if ($bid && biz_branch() === null) { $w[] = 'id IN (SELECT document_id FROM biz_doc_meta WHERE branch_id = ?)'; $x[] = $bid; }
$W = ' WHERE ' . implode(' AND ', $w); $c = $pdo->prepare('SELECT COUNT(*) FROM biz_documents' . $W); $c->execute($x); $total = (int) $c->fetchColumn(); $pages = max(1, (int) ceil($total / $per)); $page = min($page, $pages);
if (isset($_GET['csv'])) { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="documents.csv"'); $o = fopen('php://output', 'w'); fputcsv($o, ['Number', 'Type', 'Status', 'Date', 'Customer', 'Currency', 'Total']);
    $s = $pdo->prepare('SELECT * FROM biz_documents' . $W . ' ORDER BY id DESC LIMIT 5000'); $s->execute($x); foreach ($s as $d) fputcsv($o, [$d['doc_no'], $d['doc_type'], $d['status'], $d['doc_date'], $d['customer_name'], $d['currency'], $d['total']]); exit; }
$s = $pdo->prepare('SELECT * FROM biz_documents' . $W . ' ORDER BY id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)); $s->execute($x); $rows = $s->fetchAll();
$qs = fn(array $o) => http_build_query(array_filter(array_merge(['type' => $type, 'status' => $st, 'q' => $q], $o), fn($v) => $v !== '' && $v !== null));
biz_head('Documents', 'docs'); ?>
<div class="chips"><a href="docs.php?<?= h($qs(['type' => ''])) ?>" class="<?= $type === '' ? 'on' : '' ?>">All</a><?php foreach (BIZ_TYPES as $t): ?><a href="docs.php?<?= h($qs(['type' => $t])) ?>" class="<?= $type === $t ? 'on' : '' ?>"><?= h(DOC_TYPES[$t]['plural']) ?></a><?php endforeach; ?></div>
<form class="bar" method="get"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search number or customer"><select name="status"><option value="">Any status</option><?php foreach (['draft', 'posted', 'cancelled'] as $s2): ?><option value="<?= $s2 ?>"<?= $st === $s2 ? ' selected' : '' ?>><?= ucfirst($s2) ?></option><?php endforeach; ?></select><button class="btn sm">Filter</button><a class="btn sm ghost" href="docs.php?<?= h($qs(['csv' => '1'])) ?>">Export CSV</a></form>
<div class="card flush"><?php if (!$rows): ?><p class="muted pad">No documents found.</p><?php else: ?><div class="list"><?php foreach ($rows as $d): ?>
  <a class="row" href="doc.php?id=<?= (int) $d['id'] ?>"><div><b><?= h($d['customer_name']) ?></b><small><?= h(DOC_TYPES[$d['doc_type']]['label']) ?> &middot; <span class="no"><?= h($d['doc_no']) ?></span> &middot; <?= h(fmt_date($d['doc_date'])) ?></small></div><div class="r"><b><?= h(dmoney(db_cents($d['total']), $d['currency'])) ?></b><span class="pill <?= h($d['status']) ?>"><?= h(ucfirst($d['status'])) ?></span></div></a>
<?php endforeach; ?></div><?php endif; ?></div>
<?php if ($pages > 1): ?><div class="pager"><?php if ($page > 1): ?><a href="docs.php?<?= h($qs(['page' => $page - 1])) ?>">&larr; Newer</a><?php endif; ?><span>Page <?= $page ?> of <?= $pages ?></span><?php if ($page < $pages): ?><a href="docs.php?<?= h($qs(['page' => $page + 1])) ?>">Older &rarr;</a><?php endif; ?></div><?php endif; ?>
<?php biz_foot();
