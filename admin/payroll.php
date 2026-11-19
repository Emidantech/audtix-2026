<?php
/* Payroll: prepare a monthly run from active employees, review payslips, approve and mark paid, export CSV, print a payslip.
   Uganda rules built in (PAYE bands, NSSF 5% employee + 10% employer). Check the rates with URA and NSSF before relying on them. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('payroll.view');
const PAYE_BANDS = [[235000, 0.0, 0], [335000, 0.10, 235000], [410000, 0.20, 335000], [PHP_INT_MAX, 0.30, 410000]];   // monthly, resident employee
const PAYE_BASE = [0 => 0, 1 => 0, 2 => 10000, 3 => 25000];
const PAYE_SURCHARGE_FROM = 10000000;   // extra 10% on the part above this per month
const NSSF_EMPLOYEE = 0.05, NSSF_EMPLOYER = 0.10;
function paye_ug(float $gross): float {
    $t = 0.0; foreach (PAYE_BANDS as $i => [$top, $rate, $from]) { if ($gross <= $top) { $t = PAYE_BASE[$i] + max(0, $gross - $from) * $rate; break; } }
    if ($gross > PAYE_SURCHARGE_FROM) $t += ($gross - PAYE_SURCHARGE_FROM) * 0.10;
    return round($t);
}
function slip_calc(float $basic, float $allow, float $other): array {
    $gross = $basic + $allow; $paye = paye_ug($gross); $ne = round($gross * NSSF_EMPLOYEE); $nr = round($gross * NSSF_EMPLOYER);
    return ['gross' => $gross, 'paye' => $paye, 'nssf_employee' => $ne, 'nssf_employer' => $nr, 'net' => $gross - $paye - $ne - $other];
}
function run_totals(PDO $pdo, int $run): void {
    $s = $pdo->prepare('SELECT COALESCE(SUM(gross),0) g, COALESCE(SUM(paye),0) p, COALESCE(SUM(nssf_employee + nssf_employer),0) n, COALESCE(SUM(net),0) t FROM hr_payslips WHERE run_id = ?'); $s->execute([$run]); $r = $s->fetch();
    $pdo->prepare('UPDATE hr_payroll_runs SET gross_total=?, paye_total=?, nssf_total=?, net_total=?, updated_at=? WHERE id=?')->execute([$r['g'], $r['p'], $r['n'], $r['t'], now(), $run]);
}
ob_start();
$self = 'payroll.php'; $runId = (int) ($_GET['run'] ?? $_POST['run'] ?? 0); $slipId = (int) ($_GET['slip'] ?? 0);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'create') {
        deny_unless('payroll.run', $self); $per = (string) ($_POST['period'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $per)) { flash('Choose a month.', 'bad'); redirect($self); }
        $x = $pdo->prepare('SELECT id FROM hr_payroll_runs WHERE period = ?'); $x->execute([$per]); if ($x->fetchColumn()) { flash('A payroll run for ' . $per . ' already exists.', 'bad'); redirect($self); }
        $emps = $pdo->query("SELECT * FROM hr_employees WHERE status IN ('active','on_leave') ORDER BY full_name")->fetchAll();
        if (!$emps) { flash('Add at least one active employee first.', 'bad'); redirect($self); }
        $pdo->prepare("INSERT INTO hr_payroll_runs (period, status, created_by, created_at, updated_at) VALUES (?, 'draft', ?, ?, ?)")->execute([$per, (int) $admin['id'], now(), now()]); $rid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO hr_payslips (run_id, employee_id, emp_name, basic, allowances, gross, paye, nssf_employee, nssf_employer, other_deductions, net) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($emps as $e) { $c = slip_calc((float) $e['basic_salary'], (float) $e['allowances'], 0); $ins->execute([$rid, $e['id'], $e['full_name'], $e['basic_salary'], $e['allowances'], $c['gross'], $c['paye'], $c['nssf_employee'], $c['nssf_employer'], 0, $c['net']]); }
        run_totals($pdo, $rid); log_activity((int) $admin['id'], 'payroll_create', $per); flash('Payroll for ' . $per . ' prepared. Review it, then approve.'); redirect($self . '?run=' . $rid);
    }
    $r = $pdo->prepare('SELECT * FROM hr_payroll_runs WHERE id = ?'); $r->execute([$runId]); $run = $r->fetch(); if (!$run) redirect($self);
    if ($do === 'deduct' && $run['status'] === 'draft') {
        deny_unless('payroll.run', $self . '?run=' . $runId); $sid = (int) $_POST['slip']; $o = max(0, (float) str_replace(',', '', (string) $_POST['other']));
        $s = $pdo->prepare('SELECT * FROM hr_payslips WHERE id = ? AND run_id = ?'); $s->execute([$sid, $runId]); if ($sl = $s->fetch()) { $c = slip_calc((float) $sl['basic'], (float) $sl['allowances'], $o);
            $pdo->prepare('UPDATE hr_payslips SET other_deductions=?, net=? WHERE id=?')->execute([$o, $c['net'], $sid]); run_totals($pdo, $runId); flash('Deduction saved.'); }
    } elseif ($do === 'approve' && $run['status'] === 'draft') { deny_unless('payroll.approve', $self); $pdo->prepare("UPDATE hr_payroll_runs SET status='approved', updated_at=? WHERE id=?")->execute([now(), $runId]); log_activity((int) $admin['id'], 'payroll_approve', $run['period']); flash('Payroll approved.'); }
    elseif ($do === 'paid' && $run['status'] === 'approved') { deny_unless('payroll.approve', $self); $pdo->prepare("UPDATE hr_payroll_runs SET status='paid', updated_at=? WHERE id=?")->execute([now(), $runId]); log_activity((int) $admin['id'], 'payroll_paid', $run['period']); flash('Marked as paid.'); }
    elseif ($do === 'remove' && $run['status'] === 'draft') { deny_unless('payroll.run', $self); $pdo->prepare('DELETE FROM hr_payslips WHERE run_id = ?')->execute([$runId]); $pdo->prepare('DELETE FROM hr_payroll_runs WHERE id = ?')->execute([$runId]); log_activity((int) $admin['id'], 'payroll_remove', $run['period']); flash('Draft payroll removed.'); redirect($self); }
    redirect($self . '?run=' . $runId);
  }

  if (($_GET['csv'] ?? '') !== '' && $runId) {
    $r = $pdo->prepare('SELECT period FROM hr_payroll_runs WHERE id = ?'); $r->execute([$runId]); $per = $r->fetchColumn(); if ($per === false) redirect($self);
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="payroll-' . $per . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['Employee', 'Basic', 'Allowances', 'Gross', 'PAYE', 'NSSF employee 5%', 'NSSF employer 10%', 'Other deductions', 'Net pay']);
    $s = $pdo->prepare('SELECT * FROM hr_payslips WHERE run_id = ? ORDER BY emp_name'); $s->execute([$runId]); foreach ($s as $p) fputcsv($o, [$p['emp_name'], $p['basic'], $p['allowances'], $p['gross'], $p['paye'], $p['nssf_employee'], $p['nssf_employer'], $p['other_deductions'], $p['net']]); exit;
  }

  if ($slipId) {   // printable payslip
    $s = $pdo->prepare('SELECT p.*, r.period, r.status, e.job_title, e.emp_no, e.tin, e.nssf_no FROM hr_payslips p JOIN hr_payroll_runs r ON r.id = p.run_id LEFT JOIN hr_employees e ON e.id = p.employee_id WHERE p.id = ?'); $s->execute([$slipId]); $p = $s->fetch();
    if (!$p) redirect($self);
    page_head('Payslip', $admin, 'hr:payroll', [['Payroll', $self], [$p['period'], $self . '?run=' . $p['run_id']], ['Payslip', null]], '<button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>');
    $co = doc_setting('company_name', 'Audtix Systems Ltd');
    echo '<div class="card"><div class="card-body"><h4 class="mb-0">' . h($co) . '</h4><div class="text-secondary mb-3">Payslip for ' . h($p['period']) . '</div><div class="row mb-3"><div class="col-sm-6"><b>' . h($p['emp_name']) . '</b><br>' . h((string) $p['job_title']) . '<br>' . h((string) $p['emp_no']) . '</div><div class="col-sm-6">TIN: ' . h((string) $p['tin']) . '<br>NSSF no.: ' . h((string) $p['nssf_no']) . '</div></div><table class="table table-sm w-auto"><tbody>';
    foreach ([['Basic salary', $p['basic']], ['Allowances', $p['allowances']], ['Gross pay', $p['gross']], ['PAYE', -$p['paye']], ['NSSF (employee 5%)', -$p['nssf_employee']], ['Other deductions', -$p['other_deductions']]] as [$l, $v]) echo '<tr><td>' . h($l) . '</td><td class="text-end">' . mod_money($v) . '</td></tr>';
    echo '<tr class="fw-bold"><td>Net pay</td><td class="text-end">' . mod_money($p['net']) . '</td></tr></tbody></table><div class="small text-secondary">Employer NSSF (10%): ' . mod_money($p['nssf_employer']) . ' (not deducted from pay). Amounts in UGX.</div></div></div>';
    page_foot(); exit;
  }

  page_head('Payroll', $admin, 'hr:payroll');
  if ($runId) {
    $r = $pdo->prepare('SELECT * FROM hr_payroll_runs WHERE id = ?'); $r->execute([$runId]); $run = $r->fetch();
    if (!$run) { echo '<div class="alert alert-danger">Payroll run not found.</div>'; page_foot(); exit; }
    $sl = $pdo->prepare('SELECT * FROM hr_payslips WHERE run_id = ? ORDER BY emp_name'); $sl->execute([$runId]); $slips = $sl->fetchAll();
    echo '<div class="card"><div class="card-body d-flex flex-wrap gap-2 align-items-center"><div class="me-auto"><h5 class="mb-0">Payroll ' . h($run['period']) . ' ' . mod_badge($run['status']) . '</h5><div class="small text-secondary">Gross ' . mod_money($run['gross_total']) . ' &middot; PAYE ' . mod_money($run['paye_total']) . ' &middot; NSSF (5% + 10%) ' . mod_money($run['nssf_total']) . ' &middot; Net pay <b>' . mod_money($run['net_total']) . '</b> UGX</div></div>'
       . '<a class="btn btn-sm btn-outline-secondary" href="' . $self . '?run=' . $runId . '&csv=1"><i class="bi bi-filetype-csv"></i> Export CSV</a>'
       . '<form method="post" class="d-flex gap-2 m-0">' . csrf_field() . '<input type="hidden" name="run" value="' . $runId . '">'
       . ($run['status'] === 'draft' && can('payroll.approve') ? '<button class="btn btn-sm btn-success" name="do" value="approve" data-confirm="Approve this payroll? Amounts can no longer be changed.">Approve</button>' : '')
       . ($run['status'] === 'approved' && can('payroll.approve') ? '<button class="btn btn-sm btn-primary" name="do" value="paid" data-confirm="Mark this payroll as paid?">Mark as paid</button>' : '')
       . ($run['status'] === 'draft' && can('payroll.run') ? '<button class="btn btn-sm btn-outline-danger" name="do" value="remove" data-confirm="Remove this draft payroll? Employees are not affected.">Remove draft</button>' : '') . '</form></div></div>';
    echo '<div class="card"><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Employee</th><th class="text-end">Gross</th><th class="text-end">PAYE</th><th class="text-end">NSSF 5%</th><th class="text-end">Other deductions</th><th class="text-end">Net pay</th><th></th></tr></thead><tbody>';
    foreach ($slips as $p) {
        echo '<tr><td class="fw-semibold">' . h($p['emp_name']) . '</td><td class="text-end">' . mod_money($p['gross']) . '</td><td class="text-end">' . mod_money($p['paye']) . '</td><td class="text-end">' . mod_money($p['nssf_employee']) . '</td><td class="text-end">';
        if ($run['status'] === 'draft' && can('payroll.run')) echo '<form method="post" class="d-flex gap-1 justify-content-end m-0">' . csrf_field() . '<input type="hidden" name="run" value="' . $runId . '"><input type="hidden" name="slip" value="' . (int) $p['id'] . '"><input class="form-control form-control-sm text-end" style="width:90px" name="other" value="' . h(rtrim(rtrim(number_format((float) $p['other_deductions'], 2, '.', ''), '0'), '.')) . '" inputmode="decimal"><button class="btn btn-sm btn-outline-primary" name="do" value="deduct" title="Save">Set</button></form>';
        else echo mod_money($p['other_deductions']);
        echo '</td><td class="text-end fw-semibold">' . mod_money($p['net']) . '</td><td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="' . $self . '?slip=' . (int) $p['id'] . '"><i class="bi bi-receipt"></i></a></td></tr>';
    }
    echo '</tbody></table></div></div><p class="small text-secondary">PAYE bands, the 10% surcharge above UGX 10,000,000 and NSSF (employee 5%, employer 10%) follow current Uganda rules as we understand them. Confirm the rates with URA and NSSF before paying staff.</p>';
  } else {
    if (can('payroll.run')) echo '<div class="card"><div class="card-body"><form method="post" class="row g-2 align-items-end">' . csrf_field() . '<input type="hidden" name="do" value="create"><div class="col-auto"><label class="form-label mb-1">Prepare payroll for month</label><input class="form-control" type="month" name="period" value="' . h(gmdate('Y-m')) . '" required></div><div class="col-auto"><button class="btn btn-primary">Prepare payroll</button></div><div class="col-12 small text-secondary">Uses every active employee\'s basic salary and allowances. You can add other deductions before approving.</div></form></div></div>';
    $runs = $pdo->query('SELECT * FROM hr_payroll_runs ORDER BY period DESC LIMIT 60')->fetchAll();
    echo '<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead><tr><th>Month</th><th>Status</th><th class="text-end">Gross</th><th class="text-end">PAYE</th><th class="text-end">NSSF</th><th class="text-end">Net pay</th></tr></thead><tbody>';
    foreach ($runs as $r) echo '<tr><td><a class="fw-semibold" href="' . $self . '?run=' . (int) $r['id'] . '">' . h($r['period']) . '</a></td><td>' . mod_badge($r['status']) . '</td><td class="text-end">' . mod_money($r['gross_total']) . '</td><td class="text-end">' . mod_money($r['paye_total']) . '</td><td class="text-end">' . mod_money($r['nssf_total']) . '</td><td class="text-end">' . mod_money($r['net_total']) . '</td></tr>';
    if (!$runs) echo '<tr><td colspan="6" class="text-secondary">No payroll runs yet.</td></tr>';
    echo '</tbody></table></div></div>';
  }
} catch (Throwable $e) { ob_clean(); page_head('Payroll', $admin, 'hr:payroll'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
