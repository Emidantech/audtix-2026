<?php
/* Business accounts: create and manage the businesses that use the document service at /biz/. Add-only: accounts are suspended, never deleted. */
require __DIR__ . '/inc/boot.php';
$admin = require_login(); $pdo = db(); require_perm('bizacc.view'); $self = 'biz-accounts.php';
function ba_pw(): string { $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = ''; for ($i = 0; $i < 9; $i++) $o .= $c[random_int(0, strlen($c) - 1)]; return $o . random_int(10, 99); }
ob_start();
try {
  $now = gmdate('Y-m-d H:i:s'); $newPw = null;
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
            log_activity((int) $admin['id'], 'biz_account_create', $name); flash("Account created. Sign-in: $em / temporary password: $pw (shown once; they must change it at first sign-in)."); redirect($self);
        }
    } elseif ($do === 'save' && $id) {
        deny_unless('bizacc.edit', $self);
        $st = in_array($_POST['status'] ?? '', ['trial', 'active', 'suspended'], true) ? $_POST['status'] : 'trial';
        $d = fn($k) => (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST[$k] ?? '')) ? $_POST[$k] : null);
        $pdo->prepare('UPDATE biz_accounts SET status=?, plan=?, paid_until=?, trial_ends=?, monthly_doc_limit=?, admin_notes=?, updated_at=? WHERE id=?')
            ->execute([$st, mb_substr(trim((string) ($_POST['plan'] ?? 'Standard')), 0, 40) ?: 'Standard', $d('paid_until'), $d('trial_ends'), max(0, (int) ($_POST['monthly_doc_limit'] ?? 0)), mb_substr(trim((string) ($_POST['admin_notes'] ?? '')), 0, 1000), $now, $id]);
        log_activity((int) $admin['id'], 'biz_account_edit', (string) $id, $st); flash('Account updated.'); redirect($self);
    } elseif ($do === 'reset' && $id) {
        deny_unless('bizacc.edit', $self); $pw = ba_pw();
        $pdo->prepare("UPDATE biz_users SET password_hash = ?, must_change_pw = 1, failed_logins = 0, locked_until = NULL WHERE account_id = ? AND role = 'owner'")->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
        log_activity((int) $admin['id'], 'biz_account_reset', (string) $id); flash("Owner password reset. Temporary password: $pw (shown once)."); redirect($self);
    }
    redirect($self);
  }
  $rows = $pdo->query('SELECT a.*, (SELECT COUNT(*) FROM biz_documents d WHERE d.account_id = a.id) AS docs FROM biz_accounts a ORDER BY a.id DESC LIMIT 300')->fetchAll();
  $can = can('bizacc.edit'); $today = gmdate('Y-m-d');
  page_head('Business accounts', $admin, 'bizacc');
  echo '<p class="text-muted">Businesses using the document service (quotations, invoices, proforma invoices and receipts). Portal: <a href="../biz/" target="_blank" rel="noopener">/biz/</a>. Sign-up page: <a href="../biz/signup.php" target="_blank" rel="noopener">/biz/signup.php</a>. Accounts are suspended, never deleted.</p>';
  if (can('bizacc.add')) {
    echo '<div class="card"><div class="card-header"><h3 class="card-title">Create account</h3></div><div class="card-body"><form method="post" class="row g-3">' . csrf_field() . '<input type="hidden" name="do" value="create">'
       . '<div class="col-md-4"><label class="form-label">Business name</label><input class="form-control" name="name" required maxlength="190"></div><div class="col-md-3"><label class="form-label">Owner name</label><input class="form-control" name="owner_name" required maxlength="190"></div>'
       . '<div class="col-md-3"><label class="form-label">Owner email (login)</label><input class="form-control" type="email" name="email" required></div><div class="col-md-2"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="60"></div>'
       . '<div class="col-md-2"><label class="form-label">Trial days</label><input class="form-control" type="number" name="trial_days" value="14" min="0" max="365"></div><div class="col-12"><button class="btn btn-primary">Create account</button></div></form></div></div>';
  }
  echo '<div class="card"><div class="card-header"><h3 class="card-title">Accounts (' . count($rows) . ')</h3></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Business</th><th>Status</th><th>Docs</th><th>Edit</th></tr></thead><tbody>';
  foreach ($rows as $r) {
    $end = ($r['paid_until'] && $r['paid_until'] >= $today) ? $r['paid_until'] : $r['trial_ends'];
    $live = $r['status'] !== 'suspended' && ($r['status'] === 'active' && !$r['paid_until'] || ($end && $end >= $today));
    echo '<tr><td><b>' . h($r['name']) . '</b><div class="small text-muted">' . h($r['owner_name'] . ' · ' . $r['email'] . ' · ' . $r['phone']) . '</div></td><td>' . ($live ? '<span class="badge bg-success">' . h(ucfirst($r['status'])) . '</span>' : '<span class="badge bg-danger">' . ($r['status'] === 'suspended' ? 'Suspended' : 'Expired') . '</span>') . '<div class="small text-muted">' . h($r['plan']) . ($end ? ' · to ' . h($end) : '') . '</div></td><td>' . (int) $r['docs'] . '</td><td>';
    if ($can) {
      echo '<form method="post" class="row g-1">' . csrf_field() . '<input type="hidden" name="do" value="save"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
         . '<div class="col-6"><select class="form-select form-select-sm" name="status">';
      foreach (['trial' => 'Trial', 'active' => 'Active', 'suspended' => 'Suspended'] as $k => $l) echo '<option value="' . $k . '"' . ($r['status'] === $k ? ' selected' : '') . '>' . $l . '</option>';
      echo '</select></div><div class="col-6"><input class="form-control form-control-sm" name="plan" value="' . h($r['plan']) . '" placeholder="Plan" maxlength="40"></div>'
         . '<div class="col-6"><label class="small">Paid until</label><input class="form-control form-control-sm" type="date" name="paid_until" value="' . h((string) $r['paid_until']) . '"></div><div class="col-6"><label class="small">Trial ends</label><input class="form-control form-control-sm" type="date" name="trial_ends" value="' . h((string) $r['trial_ends']) . '"></div>'
         . '<div class="col-6"><label class="small">Docs/month (0 = no limit)</label><input class="form-control form-control-sm" type="number" min="0" name="monthly_doc_limit" value="' . (int) $r['monthly_doc_limit'] . '"></div><div class="col-6"><label class="small">Notes</label><input class="form-control form-control-sm" name="admin_notes" maxlength="1000" value="' . h((string) $r['admin_notes']) . '"></div>'
         . '<div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div></form>'
         . '<form method="post" class="mt-1" data-confirm="Reset the owner password and show a new temporary one?">' . csrf_field() . '<input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><button class="btn btn-sm btn-outline-secondary">Reset owner password</button></form>';
    }
    echo '</td></tr>';
  }
  if (!$rows) echo '<tr><td colspan="4" class="text-muted">No business accounts yet.</td></tr>';
  echo '</tbody></table></div></div>';
} catch (Throwable $e) { ob_clean(); page_head('Business accounts', $admin, 'bizacc'); mod_setup_notice($e); }
page_foot(); ob_end_flush();
