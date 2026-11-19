<?php
/* SaaS subscription payments: history, reconciliation of mobile-money / bank payments, manual recording, revenue totals, CSV export. A payment only activates a plan once it is verified here or by a signed provider webhook. */
require __DIR__ . '/inc/boot.php'; require_once __DIR__ . '/../biz/inc/saas.php'; require_once __DIR__ . '/../biz/inc/pay.php';
$admin = require_login(); $pdo = db(); require_perm('bizpay.view'); $self = 'biz-payments.php';
ob_start();
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    deny_unless('bizpay.edit', $self); $do = (string) ($_POST['do'] ?? ''); $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'confirm' && $id) {
        $s = $pdo->prepare('SELECT * FROM biz_payments WHERE id = ?'); $s->execute([$id]); $p = $s->fetch(); $ref = mb_substr(trim((string) ($_POST['provider_ref'] ?? '')), 0, 120);
        if (!$p || $p['status'] === 'succeeded') flash('Nothing to confirm.', 'bad'); elseif ($ref === '') flash('Enter the transaction ID from the mobile money or bank statement.', 'bad');
        else { biz_payment_succeed($pdo, $p, $ref, 'Confirmed by ' . $admin['full_name']); log_activity((int) $admin['id'], 'biz_payment_confirm', $p['reference']); flash('Payment confirmed and plan activated.'); }
    } elseif ($do === 'fail' && $id) { $pdo->prepare("UPDATE biz_payments SET status = 'failed', note = ? WHERE id = ? AND status = 'pending'")->execute(['Marked failed by ' . $admin['full_name'], $id]); log_activity((int) $admin['id'], 'biz_payment_fail', (string) $id); flash('Marked as failed.'); }
    elseif ($do === 'record') {
        $aid = (int) ($_POST['account_id'] ?? 0); $plan = biz_plan_row((int) ($_POST['plan_id'] ?? 0)); $ccy = ($_POST['currency'] ?? '') === 'USD' ? 'USD' : 'UGX'; $amt = number_format(max(0, (float) str_replace(',', '', (string) ($_POST['amount'] ?? 0))), 2, '.', ''); $ref = mb_substr(trim((string) ($_POST['provider_ref'] ?? '')), 0, 120);
        $ok = $pdo->prepare('SELECT COUNT(*) FROM biz_accounts WHERE id = ?'); $ok->execute([$aid]);
        if (!$plan || !(int) $ok->fetchColumn() || (float) $amt <= 0 || $ref === '') flash('Choose the business and plan, and enter the amount and transaction reference.', 'bad');
        else { $r = biz_new_reference(); $pdo->prepare("INSERT INTO biz_payments (account_id, plan_id, reference, provider, amount, currency, status, created_at) VALUES (?,?,?,?,?,?, 'pending', ?)")->execute([$aid, $plan['id'], $r, in_array($_POST['provider'] ?? '', ['mtn', 'airtel', 'bank', 'cash'], true) ? $_POST['provider'] : 'bank', $amt, $ccy, gmdate('Y-m-d H:i:s')]);
            $s = $pdo->prepare('SELECT * FROM biz_payments WHERE reference = ?'); $s->execute([$r]); biz_payment_succeed($pdo, $s->fetch(), $ref, 'Recorded by ' . $admin['full_name']); log_activity((int) $admin['id'], 'biz_payment_record', $r); flash("Payment $r recorded and plan activated."); }
    }
    redirect($self);
  }
  $st = in_array($_GET['status'] ?? '', ['pending', 'succeeded', 'failed'], true) ? $_GET['status'] : '';
  $q = $pdo->prepare('SELECT p.*, a.name business, pl.name plan_name FROM biz_payments p LEFT JOIN biz_accounts a ON a.id = p.account_id LEFT JOIN biz_plans pl ON pl.id = p.plan_id' . ($st ? ' WHERE p.status = ?' : '') . ' ORDER BY p.id DESC LIMIT 300'); $q->execute($st ? [$st] : []); $rows = $q->fetchAll();
  if (isset($_GET['csv'])) { header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="subscription-payments.csv"'); $o = fopen('php://output', 'w'); fputcsv($o, ['Reference', 'Business', 'Plan', 'Provider', 'Amount', 'Currency', 'Status', 'Provider ref', 'Created', 'Verified']); foreach ($rows as $r) fputcsv($o, [$r['reference'], $r['business'], $r['plan_name'], $r['provider'], $r['amount'], $r['currency'], $r['status'], $r['provider_ref'], $r['created_at'], $r['verified_at']]); exit; }
  $rev = $pdo->query("SELECT currency, COALESCE(SUM(amount),0) t FROM biz_payments WHERE status = 'succeeded' GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR); $pend = (int) $pdo->query("SELECT COUNT(*) FROM biz_payments WHERE status = 'pending'")->fetchColumn();
  $accts = $pdo->query('SELECT id, name FROM biz_accounts ORDER BY name LIMIT 500')->fetchAll(); $plans = $pdo->query('SELECT id, name FROM biz_plans ORDER BY sort_order')->fetchAll(); $can = can('bizpay.edit');
  page_head('Subscription payments', $admin, 'bizacc:pay');
  echo '<div class="row g-3 mb-3"><div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Verified revenue</div><b>' . h(implode(' · ', array_map(fn($c, $t) => number_format((float) $t, 2) . ' ' . $c, array_keys($rev), $rev)) ?: 'None yet') . '</b></div></div></div><div class="col-md-4"><div class="card"><div class="card-body"><div class="text-muted small">Waiting to be confirmed</div><b>' . $pend . '</b></div></div></div></div>';
  echo '<div class="mb-3">';
  foreach (['' => 'All', 'pending' => 'Pending', 'succeeded' => 'Succeeded', 'failed' => 'Failed'] as $k => $l) echo '<a class="btn btn-sm ' . ($st === $k ? 'btn-primary' : 'btn-outline-primary') . '" href="?status=' . $k . '">' . $l . '</a> ';
  echo '<a class="btn btn-sm btn-outline-secondary" href="?csv=1' . ($st ? '&status=' . $st : '') . '">Export CSV</a></div>';
  echo '<div class="card"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Reference</th><th>Business</th><th>Amount</th><th>Status</th><th></th></tr></thead><tbody>';
  foreach ($rows as $r) {
    echo '<tr><td><b>' . h($r['reference']) . '</b><div class="small text-muted">' . h($r['provider'] . ' · ' . substr($r['created_at'], 0, 16)) . '</div></td><td>' . h((string) $r['business']) . '<div class="small text-muted">' . h((string) $r['plan_name']) . '</div></td><td>' . h(number_format((float) $r['amount'], 2) . ' ' . $r['currency']) . '</td><td><span class="badge bg-' . ($r['status'] === 'succeeded' ? 'success' : ($r['status'] === 'failed' ? 'danger' : 'warning text-dark')) . '">' . h(ucfirst($r['status'])) . '</span><div class="small text-muted">' . h((string) $r['provider_ref']) . ' ' . h((string) $r['note']) . '</div></td><td>';
    if ($can && $r['status'] === 'pending') echo '<form method="post" class="d-flex gap-1">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><input class="form-control form-control-sm" name="provider_ref" placeholder="Transaction ID" maxlength="120"><button class="btn btn-sm btn-success" name="do" value="confirm">Confirm</button><button class="btn btn-sm btn-outline-danger" name="do" value="fail">Fail</button></form>';
    echo '</td></tr>';
  }
  if (!$rows) echo '<tr><td colspan="5" class="text-muted">No payments.</td></tr>'; echo '</tbody></table></div></div>';
  if ($can) { echo '<div class="card"><div class="card-header"><h3 class="card-title">Record a payment received outside the portal</h3></div><div class="card-body"><form method="post" class="row g-2">' . csrf_field() . '<input type="hidden" name="do" value="record"><div class="col-md-3"><select class="form-select" name="account_id">'; foreach ($accts as $a) echo '<option value="' . (int) $a['id'] . '">' . h($a['name']) . '</option>';
    echo '</select></div><div class="col-md-2"><select class="form-select" name="plan_id">'; foreach ($plans as $p) echo '<option value="' . (int) $p['id'] . '">' . h($p['name']) . '</option>'; echo '</select></div><div class="col-md-2"><input class="form-control" name="amount" placeholder="Amount"></div><div class="col-md-1"><select class="form-select" name="currency"><option>UGX</option><option>USD</option></select></div><div class="col-md-1"><select class="form-select" name="provider"><option value="mtn">MTN</option><option value="airtel">Airtel</option><option value="bank">Bank</option><option value="cash">Cash</option></select></div><div class="col-md-2"><input class="form-control" name="provider_ref" placeholder="Transaction ID" required></div><div class="col-md-1"><button class="btn btn-primary w-100">Record</button></div></form></div></div>'; }
} catch (Throwable $e) { ob_clean(); page_head('Subscription payments', $admin, 'bizacc:pay'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
