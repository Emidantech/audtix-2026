<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_once __DIR__ . '/inc/pdf.php';
require_once __DIR__ . '/inc/xlsx.php';
require_once __DIR__ . '/inc/render.php';
require_once __DIR__ . '/inc/reports.php';
$admin = require_login(); $pdo = db();
if (!dcan('report.view', $admin) && !can('quotes.view') && !can('requests.view')) doc_need('report.view');
$p = ['report' => $_GET['report'] ?? 'invoice', 'period' => $_GET['period'] ?? 'month', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'customer_id' => (int) ($_GET['customer_id'] ?? 0), 'status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '', 'created_by' => (int) ($_GET['created_by'] ?? 0), 'doc_type' => $_GET['doc_type'] ?? 'invoice'];
$types = report_types(); if (!isset($types[$p['report']])) $p['report'] = (string) array_key_first($types);
$rep = build_report($pdo, $p);
$exp = $_GET['export'] ?? '';
if ($exp === 'pdf' || $exp === 'xlsx') {
    $base = preg_replace('/[^A-Za-z0-9]+/', '-', $rep['title'] . '-' . $rep['from'] . '-to-' . $rep['to']);
    log_activity((int) $admin['id'], 'report_export', $p['report'], $exp . ' ' . $rep['from'] . ' to ' . $rep['to']);
    if ($exp === 'pdf') { $b = report_pdf($rep); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="' . $base . '.pdf"'); echo $b; exit; }
    $b = report_xlsx($rep);
    if ($b === null) { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $base . '.csv"'); $o = fopen('php://output', 'w'); fputcsv($o, array_map(fn($c) => $c[0], $rep['columns'])); foreach ($rep['rows'] as $r) { $x = []; foreach ($rep['columns'] as $c) $x[] = $c[2] === 'm' ? dec((int) ($r[$c[1]] ?? 0)) : ($r[$c[1]] ?? ''); fputcsv($o, $x); } exit; }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . $base . '.xlsx"'); echo $b; exit;
}
$customers = $pdo->query('SELECT id, name FROM customers ORDER BY name LIMIT 500')->fetchAll(); $admins = $pdo->query('SELECT id, full_name FROM admins ORDER BY full_name')->fetchAll();
$qs = http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== 0));
dm_head('Reports', $admin, 'doc_reports');
?>
<div class="hdr"><h1>Reports</h1><span class="spacer"></span><a class="btn ghost" href="?<?= h($qs) ?>&export=xlsx">Export Excel</a><a class="btn" href="?<?= h($qs) ?>&export=pdf">Export PDF</a></div>
<form class="filters card" method="get">
  <label>Report<select name="report"><?php foreach (report_types() as $k => $l) echo '<option value="' . $k . '"' . ($p['report'] === $k ? ' selected' : '') . '>' . h($l) . '</option>'; ?></select></label>
  <label>Period<select name="period"><?php foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', 'lastmonth' => 'Last month', 'quarter' => 'This quarter', 'lastquarter' => 'Last quarter', 'year' => 'This year', 'lastyear' => 'Last year', 'custom' => 'Custom range'] as $k => $l) echo '<option value="' . $k . '"' . ($p['period'] === $k ? ' selected' : '') . '>' . $l . '</option>'; ?></select></label>
  <label>From<input type="date" name="from" value="<?= h($p['from']) ?>"></label><label>To<input type="date" name="to" value="<?= h($p['to']) ?>"></label>
  <?php $isRfq = in_array($p['report'], ['rfq', 'rfq_list', 'requests'], true); if (!$isRfq): ?><label>Customer<select name="customer_id"><option value="0">All</option><?php foreach ($customers as $c) echo '<option value="' . (int) $c['id'] . '"' . ($p['customer_id'] === (int) $c['id'] ? ' selected' : '') . '>' . h($c['name']) . '</option>'; ?></select></label>
  <?php endif; ?>
  <label>Status<select name="status"><option value="">All</option><?php $sts = $p['report'] === 'requests' ? STATUSES : ($isRfq ? QUOTE_STATUSES : ['draft' => 'Draft', 'posted' => 'Posted', 'cancelled' => 'Cancelled']); foreach ($sts as $s => $lab) echo '<option value="' . $s . '"' . ($p['status'] === $s ? ' selected' : '') . '>' . h($lab) . '</option>'; ?></select></label>
  <?php if (!$isRfq): ?><label>Created by<select name="created_by"><option value="0">Anyone</option><?php foreach ($admins as $a) echo '<option value="' . (int) $a['id'] . '"' . ($p['created_by'] === (int) $a['id'] ? ' selected' : '') . '>' . h($a['full_name']) . '</option>'; ?></select></label><?php endif; ?>
  <label>Reference no. / search<input name="q" value="<?= h($p['q']) ?>" placeholder="INV-2026-00007"></label>
  <?php if ($p['report'] === 'products'): ?><label>Document type<select name="doc_type"><option value="all">All except receipts</option><?php foreach (DOC_TYPES as $k => $t) if ($k !== 'receipt') echo '<option value="' . $k . '"' . ($p['doc_type'] === $k ? ' selected' : '') . '>' . h($t['plural']) . '</option>'; ?></select></label><?php endif; ?>
  <button class="btn">Show report</button></form>
<h2><?= h($rep['title']) ?></h2><p class="muted"><?= h($rep['period']) ?><?= $rep['filters'] ? ' &middot; ' . h($rep['filters']) : '' ?></p>
<div class="stats"><?php foreach ($rep['summary'] as $s): ?><div class="stat"><b style="font-size:<?= mb_strlen($s[1]) > 18 ? 16 : 24 ?>px"><?= h($s[1]) ?></b><span><?= h($s[0]) ?></span></div><?php endforeach; ?></div>
<div class="card tablewrap" style="padding:0"><table><thead><tr><?php foreach ($rep['columns'] as $c) echo '<th' . ($c[2] !== 't' ? ' class="num"' : '') . '>' . h($c[0]) . '</th>'; ?></tr></thead><tbody>
<?php foreach ($rep['rows'] as $r): echo '<tr>'; foreach ($rep['columns'] as $c) { $v = $r[$c[1]] ?? ''; if ($c[2] === 'm') echo '<td class="num">' . dmoney((int) $v, (string) ($r['cur'] ?? 'UGX')) . '</td>'; elseif ($c[2] === 'n') echo '<td class="num">' . (int) $v . '</td>'; elseif ($c[1] === 'no' && !empty($r['id'])) echo '<td class="mono"><a href="doc.php?id=' . (int) $r['id'] . '">' . h($v) . '</a></td>'; elseif ($c[1] === 'status') echo '<td><span class="pill ' . h(strtolower($v)) . '">' . h($v) . '</span></td>'; else echo '<td>' . h($v) . '</td>'; } echo '</tr>'; endforeach;
if (!$rep['rows']) echo '<tr><td colspan="' . count($rep['columns']) . '" class="muted" style="padding:26px">No records for these filters.</td></tr>'; ?></tbody></table></div>
<?php dm_foot();
