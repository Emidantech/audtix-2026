<?php
declare(strict_types=1);
/* Business-accounts portal (biz/): other businesses sign in here to make their own quotations, invoices, proforma invoices and receipts.
   Separate session, tables, numbering and branding from the Audtix admin. Reuses the Audtix document layout and PDF writer. */
require dirname(__DIR__, 2) . '/api/lib/common.php';
require_once dirname(__DIR__, 2) . '/admin/inc/docs.php';
require_once dirname(__DIR__, 2) . '/admin/inc/pdf.php';
require_once dirname(__DIR__, 2) . '/admin/inc/render.php';

const BIZ_PORTAL = true;
const BIZ_TYPES = ['quotation', 'invoice', 'proforma', 'receipt'];
const BIZ_TRIAL_DAYS = 14;
const BIZ_IDLE = 7200;

require_once __DIR__ . '/saas.php';
require_once __DIR__ . '/pay.php';
function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('audtix_biz');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/biz', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1'); session_start();
}
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'");

function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf()) . '">'; }
function check_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string) ($_POST['_csrf'] ?? ''))) { http_response_code(400); exit('Security check failed. Go back, reload the page and try again.'); }
}
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok') { if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; } $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f; }
function make_hash(string $p): string { return password_hash($p, PASSWORD_DEFAULT); }
function valid_password(string $p): ?string { if (strlen($p) < 8) return 'Use at least 8 characters.'; if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Use letters and at least one number.'; return null; }
function temp_password(): string { $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = ''; for ($i = 0; $i < 9; $i++) $o .= $c[random_int(0, strlen($c) - 1)]; return $o . random_int(10, 99); }

/** Subscription state: ok = may create and post; otherwise the portal is read-only (nothing is ever deleted). */
function sub_state(array $a): array {
    $today = gmdate('Y-m-d'); $st = (string) $a['status']; $ap = (string) ($a['approval'] ?? 'approved');
    if ($ap === 'pending') return ['ok' => false, 'label' => 'Awaiting approval', 'note' => 'Your registration is being reviewed. You will be able to create documents once it is approved.', 'days' => 0];
    if ($ap === 'rejected') return ['ok' => false, 'label' => 'Not approved', 'note' => 'This registration was not approved. Contact the service provider.', 'days' => 0];
    if ($st === 'suspended') return ['ok' => false, 'label' => 'Suspended', 'note' => 'This account is suspended. Contact the service provider.', 'days' => 0];
    $end = $a['paid_until'] && $a['paid_until'] >= $today ? $a['paid_until'] : ($st === 'trial' && $a['trial_ends'] && $a['trial_ends'] >= $today ? $a['trial_ends'] : null);
    if ($st === 'active' && !$a['paid_until']) return ['ok' => true, 'label' => 'Active', 'note' => '', 'days' => 9999];
    if ($end) { $days = (int) floor((strtotime($end) - strtotime($today)) / 86400); return ['ok' => true, 'label' => $end === $a['trial_ends'] && $a['paid_until'] < $today ? 'Free trial' : 'Active', 'note' => $days <= 7 ? ($days <= 0 ? 'Ends today.' : "Ends in $days day" . ($days === 1 ? '' : 's') . '.') : '', 'days' => $days, 'until' => $end]; }
    $last = max((string) $a['paid_until'], (string) $a['trial_ends']); $grace = isset($a['grace_days']) && $a['grace_days'] !== null && $a['grace_days'] !== '' ? (int) $a['grace_days'] : (int) biz_platform('biz_grace_days', (string) BIZ_GRACE_DEFAULT);
    if ($last !== '' && $grace > 0) { $gEnd = gmdate('Y-m-d', strtotime($last . " +$grace days")); if ($gEnd >= $today) { $left = (int) floor((strtotime($gEnd) - strtotime($today)) / 86400); return ['ok' => true, 'label' => 'Grace period', 'note' => "Your subscription has ended. Renew within $left day" . ($left === 1 ? '' : 's') . ' to keep creating documents.', 'days' => 0, 'until' => $last, 'grace' => true]; } }
    return ['ok' => false, 'label' => 'Expired', 'note' => 'Your subscription has ended. You can still view and download your documents. Renew to create new ones.', 'days' => 0];
}

function me(): ?array {
    static $c = false; if ($c !== false) return $c;
    $id = (int) ($_SESSION['biz_uid'] ?? 0); if (!$id) return $c = null;
    if (time() - (int) ($_SESSION['biz_seen'] ?? 0) > BIZ_IDLE) { unset($_SESSION['biz_uid']); return $c = null; }
    try {
        $s = db()->prepare('SELECT u.*, a.name AS acct_name FROM biz_users u JOIN biz_accounts a ON a.id = u.account_id WHERE u.id = ? AND u.is_active = 1'); $s->execute([$id]); $u = $s->fetch();
    } catch (Throwable $e) { return $c = null; }
    if (!$u) { unset($_SESSION['biz_uid']); return $c = null; }
    $_SESSION['biz_seen'] = time(); return $c = $u;
}
function account(): array {
    static $a = null; if ($a) return $a; $u = me(); $s = db()->prepare('SELECT * FROM biz_accounts WHERE id = ?'); $s->execute([$u['account_id']]); $a = $s->fetch();
    biz_bootstrap($a); $a = array_merge($a, array_diff_key(biz_ext((int) $a['id']), ['account_id' => 1])); $a['_sub'] = sub_state($a); biz_set_company($a); return $a;
}
function logo_file_path(?string $f): string { $d = storage_dir() . '/biz-logos'; return ($f && preg_match('/^[a-f0-9]{24}\.png$/', $f) && is_file($d . '/' . $f)) ? $d . '/' . $f : ''; }
function biz_set_company(array $a): void {
    $GLOBALS['DOC_CO'] = ['company_name' => $a['name'], 'company_address' => (string) $a['address'], 'company_phone' => (string) $a['phone'], 'company_email' => (string) $a['email'], 'company_web' => (string) $a['web'], 'company_tin' => (string) $a['tin'],
        'bank_details' => (string) $a['bank_details'], 'terms_default' => (string) $a['terms_default'], '_logo_path' => logo_file_path($a['logo']), '_logo_url' => 'logo.php?a=' . (int) $a['id'] . '&v=' . substr(md5((string) $a['logo']), 0, 6)];
}
function biz_require(bool $write = false): array {
    $u = me();
    if (!$u) { redirect('login.php'); }
    check_csrf(); $a = account();
    if ((int) $u['must_change_pw'] && !biz_impersonating() && basename($_SERVER['SCRIPT_NAME']) !== 'settings.php') redirect('settings.php?pw=1');
    if ($write && !$a['_sub']['ok']) { flash($a['_sub']['note'], 'bad'); redirect('index.php'); }
    return $u;
}
function owner_only(array $u): void { if ($u['role'] !== 'owner') { flash('Only the account owner can do that.', 'bad'); redirect('index.php'); } }

function biz_head(string $title, string $active = ''): void {
    $u = me(); $a = $u ? account() : null; $f = flash();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . h($title) . ' | ' . h($a ? $a['name'] : 'Business documents') . '</title><meta name="robots" content="noindex"><link rel="stylesheet" href="biz.css?v=20261120a"><link rel="stylesheet" href="../admin/doc.css?v=20261119a">' . ($a ? biz_theme_css($a) : '') . '<script src="biz.js?v=20261120a" defer></script></head><body>';
    if ($u) {
        $nav = ['index.php' => ['Home', 'home', true]];
        $nav['docs.php'] = ['Documents', 'docs', bcan('docs.view') && biz_module_on('docs')]; $nav['customers.php'] = ['Customers', 'cust', biz_module_on('customers')]; $nav['items.php'] = ['Items', 'items', biz_module_on('items')];
        $nav['branches.php'] = ['Branches', 'branch', bcan('branches.manage') && biz_module_on('branches')]; $nav['reports.php'] = ['Reports', 'rep', bcan('reports.view') && biz_module_on('reports')];
        $nav['team.php'] = ['Team', 'team', bcan('team.manage') && biz_module_on('team')]; $nav['billing.php'] = ['Subscription', 'bill', bcan('billing.manage')]; $nav['settings.php'] = ['Settings', 'set', true];
        if ($imp = biz_impersonating()) echo '<div class="imp"><b>Support session.</b> Audtix staff member ' . h((string) ($imp['admin_name'] ?? '')) . ' is viewing this account. All actions are recorded. <form method="post" action="imp-exit.php">' . csrf_field() . '<button class="btn sm">Exit and return to Super Admin</button></form></div>';
        echo '<header class="top"><a class="brand" href="index.php">' . (logo_file_path($a['logo']) ? '<img src="' . h($GLOBALS['DOC_CO']['_logo_url']) . '" alt="">' : '') . '<b>' . h($a['name']) . '</b></a><nav class="tabs">';
        foreach ($nav as $href => [$l, $k, $show]) if ($show) echo '<a href="' . $href . '"' . ($active === $k ? ' class="on"' : '') . '>' . $l . '</a>';
        echo '</nav><a class="out" href="logout.php">Sign out</a></header>';
        $s = $a['_sub']; if (!$s['ok'] || $s['note']) echo '<div class="sub ' . ($s['ok'] ? 'warn' : 'bad') . '"><b>' . h($s['label']) . '.</b> ' . h($s['note']) . ' <a href="billing.php">Subscription</a></div>';
        try { $an = db()->query("SELECT title, body FROM biz_announcements WHERE expires_on IS NULL OR expires_on >= '" . gmdate('Y-m-d') . "' ORDER BY id DESC LIMIT 2")->fetchAll(); foreach ($an as $x) echo '<div class="sub note"><b>' . h($x['title']) . '.</b> ' . h($x['body']) . '</div>'; } catch (Throwable $e) {}
    }
    echo '<main class="wrap">'; if ($f) echo '<div class="flash ' . ($f[0] === 'ok' ? 'ok' : 'bad') . '">' . h($f[1]) . '</div>';
    echo '<h1 class="pt">' . h($title) . '</h1>';
}
function biz_foot(): void { echo '</main><footer class="ft">Documents by <a href="../" target="_blank" rel="noopener">Audtix Systems</a></footer></body></html>'; }

/* ===================== documents ===================== */
function biz_doc(PDO $pdo, int $accId, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM biz_documents WHERE id = ? AND account_id = ?'); $s->execute([$id, $accId]); $d = $s->fetch(); if (!$d) return null;
    [$bq, $bp] = biz_branch_sql('id'); if ($bq !== '') { $k = $pdo->prepare('SELECT COUNT(*) FROM biz_documents WHERE id = ?' . $bq); $k->execute([$id, ...$bp]); if (!(int) $k->fetchColumn()) return null; }
    $l = $pdo->prepare('SELECT * FROM biz_document_lines WHERE document_id = ? ORDER BY line_no'); $l->execute([$id]); $d['lines'] = $l->fetchAll(); return $d;
}
function biz_invoice_paid(PDO $pdo, int $invId, int $beforeId = 0): int {
    $s = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM biz_documents WHERE parent_id = ? AND doc_type = 'receipt' AND status = 'posted'" . ($beforeId ? ' AND id < ' . (int) $beforeId : '')); $s->execute([$invId]); return db_cents($s->fetchColumn());
}
function biz_context(PDO $pdo, array $d): array {
    $cx = ['paid' => 0, 'balance' => 0, 'prev_balance' => 0, 'parent' => null, 'prepared_by' => '', 'authorized_by' => '', 'inv_total' => 0, 'inv_prior' => 0];
    foreach (['created_by' => 'prepared_by', 'posted_by' => 'authorized_by'] as $col => $k) if (!empty($d[$col])) { $s = $pdo->prepare('SELECT full_name FROM biz_users WHERE id = ?'); $s->execute([$d[$col]]); $cx[$k] = (string) $s->fetchColumn(); }
    if (!empty($d['parent_id'])) { $s = $pdo->prepare('SELECT id, doc_no, doc_type, total, status FROM biz_documents WHERE id = ? AND account_id = ?'); $s->execute([$d['parent_id'], $d['account_id']]); $cx['parent'] = $s->fetch() ?: null; }
    $tot = db_cents($d['total']);
    if ($d['doc_type'] === 'invoice') { $cx['paid'] = biz_invoice_paid($pdo, (int) $d['id']); $cx['balance'] = $tot - $cx['paid']; }
    if ($d['doc_type'] === 'receipt' && $cx['parent'] && $cx['parent']['doc_type'] === 'invoice') { $cx['inv_total'] = db_cents($cx['parent']['total']); $cx['inv_prior'] = biz_invoice_paid($pdo, (int) $cx['parent']['id'], (int) $d['id']); $cx['balance'] = $cx['inv_total'] - $cx['inv_prior'] - ($d['status'] === 'cancelled' ? 0 : $tot); }
    return $cx;
}
function biz_next_no(PDO $pdo, array $a, string $type, int $year): string {
    $pre = ($a['doc_prefix'] ? strtoupper($a['doc_prefix']) . '-' : '') . DOC_TYPES[$type]['prefix'] . '-' . $year . '-';
    $s = $pdo->prepare('SELECT doc_no FROM biz_documents WHERE account_id = ? AND doc_no LIKE ? ORDER BY LENGTH(doc_no) DESC, doc_no DESC LIMIT 1'); $s->execute([$a['id'], $pre . '%']);
    $n = (int) substr((string) $s->fetchColumn(), strlen($pre)); return $pre . str_pad((string) ($n + 1), 5, '0', STR_PAD_LEFT);
}
function biz_month_count(PDO $pdo, int $accId): int { $s = $pdo->prepare('SELECT COUNT(*) FROM biz_documents WHERE account_id = ? AND created_at >= ?'); $s->execute([$accId, gmdate('Y-m-01 00:00:00')]); return (int) $s->fetchColumn(); }

/** Creates or updates a draft. $in: customer_id, customer_name/contact/phone/email/address/tin, doc_date, due_date, currency, payment_method, payment_ref, salesperson, reference_text, notes, terms, lines[], parent_id. */
function biz_save(PDO $pdo, array $u, array $a, string $type, ?int $id, array $in): array {
    $err = []; $T = DOC_TYPES[$type]; $tt = fn($k, $m) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $m);
    $cid = (int) ($in['customer_id'] ?? 0); $c = null;
    if ($cid) { $s = $pdo->prepare('SELECT * FROM biz_customers WHERE id = ? AND account_id = ?'); $s->execute([$cid, $a['id']]); $c = $s->fetch() ?: null; }
    $name = $c ? $c['name'] : $tt('customer_name', 190);
    if ($name === '') $err['customer_name'] = 'Choose a customer or type a name.';
    $date = $tt('doc_date', 10); if (!valid_date($date)) $err['doc_date'] = 'Enter a valid date.';
    $due = $tt('due_date', 10); if ($due !== '' && !valid_date($due)) $err['due_date'] = 'Enter a valid date.'; if ($T['due'] === '') $due = '';
    $cur = strtoupper($tt('currency', 3)); if (!isset(CURRENCIES[$cur])) $cur = $a['currency'];
    $calc = calc_lines((array) ($in['lines'] ?? [])); if ($calc['errors']) $err += $calc['errors'];
    if ($type === 'receipt' && $calc['totals']['total'] <= 0) $err['lines'] = 'Enter the amount received.';
    $parent = (int) ($in['parent_id'] ?? 0) ?: null;
    if ($parent) { $p = biz_doc($pdo, (int) $a['id'], $parent); if (!$p) $parent = null; }
    if ($type === 'receipt' && $parent && !$err) {
        $p = biz_doc($pdo, (int) $a['id'], $parent);
        if ($p['doc_type'] !== 'invoice' || $p['status'] !== 'posted') $err['parent'] = 'A receipt can only be linked to a posted invoice.';
        else { $bal = db_cents($p['total']) - biz_invoice_paid($pdo, $parent, $id ?: 0); if ($calc['totals']['total'] > $bal) $err['lines'] = 'The amount is more than the balance of ' . dmoney($bal, $p['currency']) . ' on ' . $p['doc_no'] . '.'; }
    }
    if ($err) return ['ok' => false, 'errors' => $err];
    if (!$c && !$cid) {   // a new customer typed on the document is remembered for next time
        $k = $pdo->prepare('SELECT id FROM biz_customers WHERE account_id = ? AND LOWER(name) = LOWER(?) LIMIT 1'); $k->execute([$a['id'], $name]); $cid = (int) $k->fetchColumn();
        if (!$cid) { $pdo->prepare('INSERT INTO biz_customers (account_id, name, contact_person, phone, email, address, tin, created_at) VALUES (?,?,?,?,?,?,?,?)')->execute([$a['id'], $name, $tt('customer_contact', 190) ?: null, $tt('customer_phone', 60) ?: null, $tt('customer_email', 190) ?: null, $tt('customer_address', 500) ?: null, $tt('customer_tin', 20) ?: null, now()]); $cid = (int) $pdo->lastInsertId(); }
    }
    $snap = $c ?: ['contact_person' => $tt('customer_contact', 190), 'phone' => $tt('customer_phone', 60), 'email' => $tt('customer_email', 190), 'address' => $tt('customer_address', 500), 'tin' => $tt('customer_tin', 20)];
    $vals = [$cid ?: null, $name, $snap['contact_person'] ?: null, $snap['tin'] ?: null, $snap['address'] ?: null, $snap['phone'] ?: null, $snap['email'] ?: null, $date, $due ?: null, $cur, $tt('payment_method', 60) ?: null, $tt('payment_ref', 120) ?: null, $tt('salesperson', 120) ?: null, $tt('reference_text', 190) ?: null, $parent,
        dec($calc['totals']['subtotal']), dec($calc['totals']['discount']), dec($calc['totals']['tax']), dec($calc['totals']['total']), $tt('notes', 2000) ?: null, $tt('terms', 4000) ?: null];
    $cols = 'customer_id, customer_name, customer_contact, customer_tin, customer_address, customer_phone, customer_email, doc_date, due_date, currency, payment_method, payment_ref, salesperson, reference_text, parent_id, subtotal, discount_total, tax_total, total, notes, terms';
    $pdo->beginTransaction();
    try {
        if ($id) { $old = biz_doc($pdo, (int) $a['id'], $id); if (!$old || $old['status'] !== 'draft') { $pdo->rollBack(); return ['ok' => false, 'errors' => ['x' => 'Only drafts can be edited.']]; }
            $pdo->prepare('UPDATE biz_documents SET ' . implode(', ', array_map(fn($c) => trim($c) . ' = ?', explode(',', $cols))) . ', updated_at = ? WHERE id = ? AND account_id = ?')->execute([...$vals, now(), $id, $a['id']]);
            $pdo->prepare('DELETE FROM biz_document_lines WHERE document_id = ?')->execute([$id]); }
        else {
            for ($try = 0; $try < 5; $try++) { $no = biz_next_no($pdo, $a, $type, (int) substr($date, 0, 4));
                try { $pdo->prepare('INSERT INTO biz_documents (account_id, doc_type, doc_no, status, created_by, created_at, updated_at, ' . $cols . ') VALUES (?,?,?,?,?,?,?,' . implode(',', array_fill(0, 21, '?')) . ')')->execute([$a['id'], $type, $no, 'draft', $u['id'], now(), now(), ...$vals]); $id = (int) $pdo->lastInsertId(); break; }
                catch (PDOException $e) { if ($try === 4) throw $e; } }
        }
        $ins = $pdo->prepare('INSERT INTO biz_document_lines (document_id, line_no, description, quantity, unit, unit_price, gross_amount, discount, tax_rate, tax_amount, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($calc['lines'] as $i => $l) $ins->execute([$id, $i + 1, $l['description'], $l['quantity'], $l['unit'] ?: null, dec($l['unit_price']), dec($l['gross']), dec($l['discount']), $l['tax_rate'], dec($l['tax']), dec($l['total'])]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'errors' => ['x' => 'Could not save: ' . $e->getMessage()]]; }
    return ['ok' => true, 'id' => $id];
}
function biz_post(PDO $pdo, array $u, array $a, int $id): array {
    $d = biz_doc($pdo, (int) $a['id'], $id); if (!$d) return ['ok' => false, 'error' => 'Not found.']; if ($d['status'] !== 'draft') return ['ok' => false, 'error' => 'Already posted.'];
    if ($d['doc_type'] === 'receipt' && $d['parent_id']) { $bal = db_cents((biz_doc($pdo, (int) $a['id'], (int) $d['parent_id']) ?? ['total' => 0])['total']) - biz_invoice_paid($pdo, (int) $d['parent_id']); if (db_cents($d['total']) > $bal) return ['ok' => false, 'error' => 'The amount is more than the invoice balance now. Edit the receipt.']; }
    $lim = biz_limit($a, 'max_docs_month'); if ($lim > 0) { $s = $pdo->prepare("SELECT COUNT(*) FROM biz_documents WHERE account_id = ? AND status IN ('posted','cancelled') AND posted_at >= ?"); $s->execute([$a['id'], gmdate('Y-m-01 00:00:00')]); if ((int) $s->fetchColumn() >= $lim) return ['ok' => false, 'error' => "Your plan allows $lim posted documents a month. Contact us to upgrade."]; }
    $pdo->prepare("UPDATE biz_documents SET status = 'posted', posted_by = ?, posted_at = ?, updated_at = ? WHERE id = ? AND account_id = ? AND status = 'draft'")->execute([$u['id'], now(), now(), $id, $a['id']]); return ['ok' => true];
}
function biz_cancel(PDO $pdo, array $u, array $a, int $id, string $reason): array {
    $d = biz_doc($pdo, (int) $a['id'], $id); if (!$d || $d['status'] !== 'posted') return ['ok' => false, 'error' => 'Only a posted document can be cancelled.']; $reason = mb_substr(trim($reason), 0, 500); if ($reason === '') return ['ok' => false, 'error' => 'Give a reason for cancelling.'];
    if ($d['doc_type'] === 'invoice' && biz_invoice_paid($pdo, $id) > 0) return ['ok' => false, 'error' => 'This invoice has receipts. Cancel those first.'];
    $pdo->prepare("UPDATE biz_documents SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?, updated_at = ? WHERE id = ? AND account_id = ?")->execute([$u['id'], now(), $reason, now(), $id, $a['id']]); return ['ok' => true];
}
/** Copies a document into a new draft of another type (quotation > invoice/proforma, proforma > invoice). */
function biz_convert(PDO $pdo, array $u, array $a, int $id, string $target): array {
    $ok = ['quotation' => ['invoice', 'proforma'], 'proforma' => ['invoice']]; $d = biz_doc($pdo, (int) $a['id'], $id);
    if (!$d || $d['status'] !== 'posted' || !in_array($target, $ok[$d['doc_type']] ?? [], true)) return ['ok' => false, 'error' => 'That conversion is not available.'];
    $lines = array_map(fn($l) => ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => $l['unit_price'], 'discount' => $l['discount'], 'tax_rate' => $l['tax_rate']], $d['lines']);
    $due = $target === 'invoice' ? (new DateTime('today'))->modify('+30 days')->format('Y-m-d') : (new DateTime('today'))->modify('+30 days')->format('Y-m-d');
    return biz_save($pdo, $u, $a, $target, null, ['customer_id' => $d['customer_id'], 'customer_name' => $d['customer_name'], 'doc_date' => gmdate('Y-m-d'), 'due_date' => $due, 'currency' => $d['currency'], 'reference_text' => $d['doc_no'], 'notes' => $d['notes'], 'terms' => $d['terms'], 'lines' => $lines, 'parent_id' => $id]);
}

function trim_num($v): string { $v = (string) $v; return str_contains($v, '.') ? rtrim(rtrim($v, '0'), '.') : $v; }
