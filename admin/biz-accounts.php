<?php
/* SaaS platform: all registered businesses. Create, approve, activate, suspend, change plan, reset owner password, and log in as a business for support (audited). Accounts are suspended, never deleted. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('bizacc.view'); $self = 'biz-accounts.php';
function ba_pw(): string { $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = ''; for ($i = 0; $i < 9; $i++) $o .= $c[random_int(0, strlen($c) - 1)]; return $o . random_int(10, 99); }
function ba_ext(PDO $pdo, int $id, array $f): void {
    $x = $pdo->prepare('SELECT COUNT(*) FROM biz_account_ext WHERE account_id = ?'); $x->execute([$id]); if (!(int) $x->fetchColumn()) $pdo->prepare("INSERT INTO biz_account_ext (account_id, approval) VALUES (?, 'approved')")->execute([$id]);
    foreach ($f as $k => $v) if (preg_match('/^[a-z_]+$/', $k)) $pdo->prepare("UPDATE biz_account_ext SET $k = ? WHERE account_id = ?")->execute([$v, $id]);
}
ob_start();
try {
  $now = gmdate('Y-m-d H:i:s');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? ''); $id = (int) ($_POST['id'] ?? 0);
    if ($do === 'create') {
        deny_unless('bizacc.add', $self);
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 190); $owner = mb_substr(trim((string) ($_POST['owner_name'] ?? '')), 0, 190); $em = strtolower(trim((string) ($_POST['email'] ?? ''))); $ph = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 60);
        $x = $pdo->prepare('SELECT COUNT(*) FROM biz_users WHERE email = ?'); $x->execute([$em]);
        if ($name === '' || $owner === '' || !filter_var($em, FILTER_VALIDATE_EMAIL)) flash('Enter the business name, owner name and a valid email.', 'bad');
        elseif ((int) $x->fetchColumn()) flash('That email already has a login.', 'bad');
        else {
            $days = max(0, min(365, (int) ($_POST['trial_days'] ?? 14))); $pw = ba_pw();
            $pdo->prepare("INSERT INTO biz_accounts (name, owner_name, email, phone, plan, status, trial_ends, created_at, updated_at) VALUES (?,?,?,?, 'Trial', 'trial', ?, ?, ?)")->execute([$name, $owner, $em, $ph, gmdate('Y-m-d', time() + $days * 86400), $now, $now]);
            $aid = (int) $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO biz_users (account_id, full_name, email, password_hash, role, must_change_pw, created_at) VALUES (?,?,?,?, 'owner', 1, ?)")->execute([$aid, $owner, $em, password_hash($pw, PASSWORD_DEFAULT), $now]);
            ba_ext($pdo, $aid, []);   // roles and the trial plan are set up on the business's first sign-in
            log_activity((int) $admin['id'], 'biz_account_create', $name); flash("Account created. Sign-in: $em / temporary password: $pw (shown once; they must change it at first sign-in)."); redirect($self);
        }
    } elseif ($do === 'save' && $id) {
        deny_unless('bizacc.edit', $self);
        $st = in_array($_POST['status'] ?? '', ['trial', 'active', 'suspended'], true) ? $_POST['status'] : 'trial';
        $d = fn($k) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$k] ?? '')) ? $_POST[$k] : null);
        $pl = (int) ($_POST['plan_id'] ?? 0); $pn = $pdo->prepare('SELECT name FROM biz_plans WHERE id = ?'); $pn->execute([$pl]); $pname = (string) $pn->fetchColumn();
        $pdo->prepare('UPDATE biz_accounts SET status=?, plan=?, paid_until=?, trial_ends=?, monthly_doc_limit=?, admin_notes=?, updated_at=? WHERE id=?')
            ->execute([$st, $pname ?: (mb_substr(trim((string) ($_POST['plan'] ?? 'Standard')), 0, 40) ?: 'Standard'), $d('paid_until'), $d('trial_ends'), max(0, (int) ($_POST['monthly_doc_limit'] ?? 0)), mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 1000), $now, $id]);
        $gd = trim((string) ($_POST['grace_days'] ?? '')); ba_ext($pdo, $id, ['plan_id' => $pl ?: null, 'grace_days' => $gd === '' ? null : max(0, min(90, (int) $gd))]);
        log_activity((int) $admin['id'], 'biz_account_edit', (string) $id, $st); flash('Account updated.'); redirect($self);
    } elseif ($do === 'approval' && $id) {
        deny_unless('bizacc.approve', $self); $v = ($_POST['value'] ?? '') === 'rejected' ? 'rejected' : 'approved'; ba_ext($pdo, $id, ['approval' => $v]);
        log_activity((int) $admin['id'], 'biz_account_' . $v, (string) $id); flash('Registration ' . $v . '.'); redirect($self);
    } elseif ($do === 'reset' && $id) {
        deny_unless('bizacc.edit', $self); $pw = ba_pw();
        $pdo->prepare("UPDATE biz_users SET password_hash = ?, must_change_pw = 1, failed_logins = 0, locked_until = NULL WHERE account_id = ? AND role = 'owner'")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        log_activity((int) $admin['id'], 'biz_account_reset', (string) $id); flash("Owner password reset. Temporary password: $pw (shown once)."); redirect($self);
    } elseif ($do === 'impersonate' && $id) {
        deny_unless('bizacc.impersonate', $self); $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300);
        if (mb_strlen($reason) < 5) { flash('Write the reason for the support session (at least a few words). It is recorded.', 'bad'); redirect($self); }
        $o = $pdo->prepare("SELECT id FROM biz_users WHERE account_id = ? AND role = 'owner' AND is_active = 1 ORDER BY id LIMIT 1"); $o->execute([$id]); $uid = (int) $o->fetchColumn();
        if (!$uid) { flash('This business has no active owner login.', 'bad'); redirect($self); }
        $tok = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO biz_imp_log (admin_id, admin_name, account_id, user_id, reason, token_hash, token_expires, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$admin['id'], $admin['full_name'], $id, $uid, $reason, hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 60), client_ip(), $now]);
        log_activity((int) $admin['id'], 'biz_support_login', (string) $id, $reason); header('Location: ../biz/impersonate.php?t=' . $tok); exit;
    }
    redirect($self);
  }
  $rows = $pdo->query('SELECT a.*, x.category, x.plan_id, x.grace_days, COALESCE(x.approval, \'approved\') AS approval,
      (SELECT COUNT(*) FROM biz_documents d WHERE d.account_id = a.id) AS docs, (SELECT COUNT(*) FROM biz_users u WHERE u.account_id = a.id AND u.is_active = 1) AS users,
      (SELECT COUNT(*) FROM biz_branches b WHERE b.account_id = a.id AND b.is_active = 1) AS branches
      FROM biz_accounts a LEFT JOIN biz_account_ext x ON x.account_id = a.id ORDER BY a.id DESC LIMIT 300')->fetchAll();
  $plans = []; try { $plans = $pdo->query('SELECT id, name FROM biz_plans ORDER BY sort_order')->fetchAll(); } catch (Throwable $e) {}
  $can = can('bizacc.edit'); $today = gmdate('Y-m-d'); $pending = count(array_filter($rows, fn($r) => $r['approval'] === 'pending'));
  page_head('Businesses', $admin, 'bizacc:accounts');
  echo '<p class="text-muted">Every business on the platform. Portal: <a href="../biz/" target="_blank" rel="noopener">/biz/</a>. Registration page: <a href="../biz/signup.php" target="_blank" rel="noopener">/biz/signup.php</a>. Accounts are suspended, never deleted.' . ($pending ? ' <b class="text-warning">' . $pending . ' awaiting approval.</b>' : '') . '</p>';
  if (can('bizacc.add')) {
    echo '<div class="card"><div class="card-header"><h3 class="card-title">Create account</h3></div><div class="card-body"><form method="post" class="row g-3">' . csrf_field() . '<input type="hidden" name="do" value="create">'
       . '<div class="col-md-4"><label class="form-label">Business name</label><input class="form-control" name="name" required maxlength="190"></div><div class="col-md-3"><label class="form-label">Owner name</label><input class="form-control" name="owner_name" required maxlength="190"></div>'
       . '<div class="col-md-3"><label class="form-label">Owner email (login)</label><input class="form-control" type="email" name="email" required></div><div class="col-md-2"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="60"></div>'
       . '<div class="col-md-2"><label class="form-label">Trial days</label><input class="form-control" type="number" name="trial_days" value="14" min="0" max="365"></div><div class="col-12"><button class="btn btn-primary">Create account</button></div></form></div></div>';
  }
  echo '<div class="card"><div class="card-header"><h3 class="card-title">Businesses (' . count($rows) . ')</h3></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Business</th><th>Status</th><th>Usage</th><th>Manage</th></tr></thead><tbody>';
  foreach ($rows as $r) {
    $end = ($r['paid_until'] && $r['paid_until'] >= $today) ? $r['paid_until'] : $r['trial_ends'];
    $live = $r['approval'] === 'approved' && $r['status'] !== 'suspended' && ($r['status'] === 'active' && !$r['paid_until'] || ($end && $end >= $today));
    echo '<tr><td><b>' . h($r['name']) . '</b><div class="small text-muted">' . h($r['owner_name'] . ' · ' . $r['email'] . ' · ' . $r['phone']) . '</div><div class="small text-muted">' . h((string) $r['category']) . '</div></td><td>'
       . ($r['approval'] === 'pending' ? '<span class="badge bg-warning text-dark">Awaiting approval</span>' : ($r['approval'] === 'rejected' ? '<span class="badge bg-danger">Rejected</span>' : ($live ? '<span class="badge bg-success">' . h(ucfirst($r['status'])) . '</span>' : '<span class="badge bg-danger">' . ($r['status'] === 'suspended' ? 'Suspended' : 'Expired') . '</span>')))
       . '<div class="small text-muted">' . h($r['plan']) . ($end ? ' · to ' . h($end) : '') . '</div></td><td class="small">' . (int) $r['users'] . ' users<br>' . (int) $r['branches'] . ' branches<br>' . (int) $r['docs'] . ' docs</td><td>';
    if (can('bizacc.approve') && $r['approval'] !== 'approved') echo '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="do" value="approval"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-sm btn-success" name="value" value="approved">Approve</button> ' . ($r['approval'] === 'pending' ? '<button class="btn btn-sm btn-outline-danger" name="value" value="rejected">Reject</button>' : '') . '</form> ';
    if (can('bizacc.impersonate')) echo '<form method="post" class="row g-1 mb-2" data-confirm="Open this business as its owner for support? This is recorded in the audit log.">' . csrf_field() . '<input type="hidden" name="do" value="impersonate"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><div class="col-8"><input class="form-control form-control-sm" name="reason" placeholder="Reason for support access" maxlength="300" required></div><div class="col-4"><button class="btn btn-sm btn-warning w-100">Login as</button></div></form>';
    if ($can) {
      echo '<form method="post" class="row g-1">' . csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
         . '<div class="col-6"><select class="form-select form-select-sm" name="status">';
      foreach (['trial' => 'Trial', 'active' => 'Active', 'suspended' => 'Suspended'] as $k => $l) echo '<option value="' . $k . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
      echo '</select></div><div class="col-6"><select class="form-select form-select-sm" name="plan_id"><option value="">No plan (all features)</option>';
      foreach ($plans as $p) echo '<option value="' . (int) $p['id'] . '"' . ((int) $r['plan_id'] === (int) $p['id'] ? ' selected' : '') . '>' . h($p['name']) . '</option>';
      echo '</select></div>'
         . '<div class="col-6"><label class="small">Paid until</label><input class="form-control form-control-sm" type="date" name="paid_until" value="' . h((string) $r['paid_until']) . '"></div><div class="col-6"><label class="small">Trial ends</label><input class="form-control form-control-sm" type="date" name="trial_ends" value="' . h((string) $r['trial_ends']) . '"></div>'
         . '<div class="col-4"><label class="small">Docs/month override</label><input class="form-control form-control-sm" type="number" min="0" name="monthly_doc_limit" value="' . (int) $r['monthly_doc_limit'] . '"></div><div class="col-4"><label class="small">Grace days</label><input class="form-control form-control-sm" type="number" min="0" max="90" name="grace_days" value="' . h((string) $r['grace_days']) . '" placeholder="default"></div><div class="col-4"><label class="small">Notes</label><input class="form-control form-control-sm" name="admin_notes" maxlength="1000" value="' . h((string) $r['admin_notes']) . '"></div>'
         . '<div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div></form>'
         . '<form method="post" class="mt-1" data-confirm="Reset the owner password and show a new temporary one?">' . csrf_field() . '<input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-sm btn-outline-secondary">Reset owner password</button></form>';
    }
    echo '</td></tr>';
  }
  if (!$rows) echo '<tr><td colspan="4" class="text-muted">No business accounts yet.</td></tr>';
  echo '</tbody></table></div></div>';
} catch (Throwable $e) { ob_clean(); page_head('Businesses', $admin, 'bizacc:accounts'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
