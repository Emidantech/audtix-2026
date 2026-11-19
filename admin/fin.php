<?php
/* Finance overview: money in from posted receipts and invoices (business documents), money out from expenses and paid payroll. UGX only. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('fin.view');
$act = can('fin.add') ? '<a class="btn btn-primary btn-sm" href="mod.php?m=fin_expenses&new=1"><i class="bi bi-plus-lg"></i> Record expense</a>' : '';
page_head('Finance overview', $admin, 'fin:overview', [], $act);
$one = function (string $sql, array $a = []) use ($pdo): float { try { $s = $pdo->prepare($sql); $s->execute($a); return (float) $s->fetchColumn(); } catch (Throwable $e) { return 0.0; } };
$m0 = gmdate('Y-m-01'); $y0 = gmdate('Y-01-01'); $today = gmdate('Y-m-d');
$docs = fn(string $t, string $from) => $one("SELECT COALESCE(SUM(total),0) FROM documents WHERE doc_type = ? AND status = 'posted' AND currency = 'UGX' AND doc_date >= ?", [$t, $from]);
$exp = fn(string $from) => $one("SELECT COALESCE(SUM(amount),0) FROM fin_expenses WHERE status <> 'rejected' AND currency = 'UGX' AND exp_date >= ?", [$from]);
$pay = fn(string $from) => $one("SELECT COALESCE(SUM(p.gross + p.nssf_employer),0) FROM hr_payslips p JOIN hr_payroll_runs r ON r.id = p.run_id WHERE r.status = 'paid' AND r.period >= ?", [substr($from, 0, 7)]);
$recM = $docs('receipt', $m0); $recY = $docs('receipt', $y0); $invM = $docs('invoice', $m0); $invY = $docs('invoice', $y0);
$expM = $exp($m0); $expY = $exp($y0); $payM = $pay($m0); $payY = $pay($y0);
$open = $one("SELECT COALESCE(SUM(total),0) FROM documents WHERE doc_type='invoice' AND status='posted' AND currency='UGX'") - $one("SELECT COALESCE(SUM(r.total),0) FROM documents r WHERE r.doc_type='receipt' AND r.status='posted' AND r.currency='UGX' AND r.parent_id IN (SELECT id FROM documents WHERE doc_type='invoice' AND status='posted')");
$overdue = $one("SELECT COALESCE(SUM(total),0) FROM documents WHERE doc_type='invoice' AND status='posted' AND currency='UGX' AND due_date < ?", [$today]);
$tile = function (string $label, float $v, string $icon, string $tone, string $sub = '') { return '<div class="col-6 col-lg-3"><div class="info-box mb-3"><span class="info-box-icon text-bg-' . $tone . '"><i class="bi bi-' . $icon . '"></i></span><div class="info-box-content"><span class="info-box-text">' . h($label) . '</span><span class="info-box-number">' . mod_money($v) . '</span>' . ($sub ? '<small class="text-secondary">' . h($sub) . '</small>' : '') . '</div></div></div>'; };
echo '<p class="text-secondary small">UGX only. Money in comes from posted receipts, money out from recorded expenses (rejected ones are left out) and paid payroll. Invoices in other currencies are not added here.</p><div class="row">';
echo $tile('Received this month', $recM, 'arrow-down-circle', 'success', 'Receipts posted'), $tile('Spent this month', $expM + $payM, 'arrow-up-circle', 'danger', 'Expenses + payroll'), $tile('Net this month', $recM - $expM - $payM, 'piggy-bank', $recM - $expM - $payM >= 0 ? 'primary' : 'warning'), $tile('Invoiced this month', $invM, 'file-earmark-text', 'info');
echo $tile('Received this year', $recY, 'cash-coin', 'success'), $tile('Spent this year', $expY + $payY, 'wallet2', 'danger', 'Expenses + payroll'), $tile('Unpaid invoices', max(0, $open), 'hourglass-split', 'warning', 'Posted invoices less receipts'), $tile('Overdue invoices', $overdue, 'exclamation-triangle', 'danger', 'Past due date, before receipts');
echo '</div>';
try {
    $cats = $pdo->prepare("SELECT category, SUM(amount) a FROM fin_expenses WHERE status <> 'rejected' AND currency = 'UGX' AND exp_date >= ? GROUP BY category ORDER BY a DESC"); $cats->execute([$y0]); $cats = $cats->fetchAll();
    $recent = $pdo->query('SELECT * FROM fin_expenses ORDER BY exp_date DESC, id DESC LIMIT 8')->fetchAll();
} catch (Throwable $e) { mod_setup_notice($e); $cats = $recent = []; }
$max = max([1] + array_map(fn($c) => (float) $c['a'], $cats));
echo '<div class="row"><div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Expenses this year by category</h3></div><div class="card-body">';
foreach ($cats as $c) echo '<div class="mb-2"><div class="d-flex justify-content-between small"><span>' . h($c['category']) . '</span><b>' . mod_money($c['a']) . '</b></div><div class="progress" style="height:6px"><div class="progress-bar" style="width:' . round($c['a'] / $max * 100) . '%"></div></div></div>';
if (!$cats) echo '<span class="text-secondary">No expenses recorded this year.</span>';
echo '</div></div></div><div class="col-lg-6"><div class="card"><div class="card-header"><h3 class="card-title">Latest expenses</h3><div class="card-tools"><a href="mod.php?m=fin_expenses">All expenses</a></div></div><div class="table-responsive"><table class="table table-sm mb-0"><tbody>';
foreach ($recent as $r) echo '<tr><td>' . h($r['exp_date']) . '</td><td>' . h($r['category']) . '<div class="small text-secondary">' . h((string) $r['payee']) . '</div></td><td class="text-end">' . mod_money($r['amount']) . ' ' . h($r['currency']) . '</td></tr>';
if (!$recent) echo '<tr><td class="text-secondary">Nothing yet.</td></tr>';
echo '</tbody></table></div></div></div></div>';
page_foot();
