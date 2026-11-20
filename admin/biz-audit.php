<?php
/* SaaS platform analytics, support-session (impersonation) log and the cross-business activity log. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('bizacc.view'); ob_start();
try {
  $n = fn($sql) => (int) $pdo->query($sql)->fetchColumn(); $today = gmdate('Y-m-d');
  $kpi = ['Businesses' => $n('SELECT COUNT(*) FROM biz_accounts'), 'Active or in trial' => $n("SELECT COUNT(*) FROM biz_accounts WHERE status <> 'suspended' AND ((paid_until >= '$today') OR (trial_ends >= '$today') OR (status = 'active' AND paid_until IS NULL))"), 'Suspended' => $n("SELECT COUNT(*) FROM biz_accounts WHERE status = 'suspended'"),
          'Users' => $n('SELECT COUNT(*) FROM biz_users WHERE is_active = 1'), 'Branches' => $n('SELECT COUNT(*) FROM biz_branches WHERE is_active = 1'), 'Documents' => $n('SELECT COUNT(*) FROM biz_documents'), 'Documents this month' => $n("SELECT COUNT(*) FROM biz_documents WHERE created_at >= '" . gmdate('Y-m-01') . "'")];
  $rev = $pdo->query("SELECT currency, COALESCE(SUM(amount),0) FROM biz_payments WHERE status = 'succeeded' GROUP BY currency")->fetchAll(PDO::FETCH_KEY_PAIR);
  $top = $pdo->query('SELECT a.name, COUNT(d.id) c FROM biz_accounts a LEFT JOIN biz_documents d ON d.account_id = a.id GROUP BY a.id, a.name ORDER BY c DESC LIMIT 8')->fetchAll();
  $imp = $pdo->query('SELECT l.*, a.name business FROM biz_imp_log l LEFT JOIN biz_accounts a ON a.id = l.account_id WHERE l.started_at IS NOT NULL ORDER BY l.id DESC LIMIT 40')->fetchAll();
  $au = $pdo->query('SELECT x.*, a.name business FROM biz_audit x LEFT JOIN biz_accounts a ON a.id = x.account_id ORDER BY x.id DESC LIMIT 80')->fetchAll();
  page_head('Audit and analytics', $admin, 'bizacc:audit');
  echo '<div class="row g-3 mb-3">'; foreach ($kpi as $k => $v) echo '<div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="text-muted small">' . h($k) . '</div><b class="fs-4">' . $v . '</b></div></div></div>'; echo '<div class="col-12 col-md-6"><div class="card"><div class="card-body"><div class="text-muted small">Verified subscription revenue</div><b class="fs-5">' . h(implode(' · ', array_map(fn($c, $t) => number_format((float) $t, 2) . ' ' . $c, array_keys($rev), $rev)) ?: 'None yet') . '</b></div></div></div></div>';
  echo '<div class="card"><div class="card-header"><h3 class="card-title">Most active businesses (documents)</h3></div><div class="card-body small">'; foreach ($top as $t) echo '<div>' . h($t['name']) . ': <b>' . (int) $t['c'] . '</b></div>'; echo '</div></div>';
  echo '<div class="card"><div class="card-header"><h3 class="card-title">Support sessions (Login as business)</h3></div><div class="table-responsive"><table class="table mb-0 small"><thead><tr><th>Started</th><th>Staff</th><th>Business</th><th>Reason</th><th>Ended</th><th>IP</th></tr></thead><tbody>';
  foreach ($imp as $l) echo '<tr><td>' . h($l['started_at']) . '</td><td>' . h((string) $l['admin_name']) . '</td><td>' . h((string) $l['business']) . '</td><td>' . h($l['reason']) . '</td><td>' . h((string) ($l['ended_at'] ?: 'open or timed out')) . '</td><td>' . h((string) $l['ip']) . '</td></tr>'; if (!$imp) echo '<tr><td colspan="6" class="text-muted">No support sessions.</td></tr>';
  echo '</tbody></table></div></div><div class="card"><div class="card-header"><h3 class="card-title">Activity across businesses</h3></div><div class="table-responsive"><table class="table mb-0 small"><thead><tr><th>When (UTC)</th><th>Business</th><th>Action</th><th>Detail</th><th>By</th></tr></thead><tbody>';
  foreach ($au as $x) echo '<tr><td>' . h($x['created_at']) . '</td><td>' . h((string) $x['business']) . '</td><td>' . h($x['action']) . '</td><td>' . h((string) $x['detail']) . '</td><td>' . ($x['admin_id'] ? 'Audtix support #' . (int) $x['admin_id'] : ($x['user_id'] ? 'user #' . (int) $x['user_id'] : 'system')) . '</td></tr>';
  echo '</tbody></table></div></div>';
} catch (Throwable $e) { ob_clean(); page_head('Audit and analytics', $admin, 'bizacc:audit'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
