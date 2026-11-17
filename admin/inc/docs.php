<?php
declare(strict_types=1);
/* Business documents engine: requisition, quotation, invoice, budget, proforma invoice, receipt.
   All money is handled as integer cents on the server; the browser's totals are never trusted. */

const DOC_TYPES = [
    'requisition' => ['label' => 'Requisition',      'plural' => 'Requisitions',      'prefix' => 'REQ', 'title' => 'REQUISITION',      'due' => 'Needed by',         'bill' => 'Requisition for'],
    'quotation'   => ['label' => 'Quotation',        'plural' => 'Quotations',        'prefix' => 'QUO', 'title' => 'QUOTATION',        'due' => 'Valid until',       'bill' => 'Quotation for'],
    'invoice'     => ['label' => 'Invoice',          'plural' => 'Invoices',          'prefix' => 'INV', 'title' => 'INVOICE',          'due' => 'Due date',          'bill' => 'Bill to'],
    'budget'      => ['label' => 'Budget',           'plural' => 'Budgets',           'prefix' => 'BUD', 'title' => 'BUDGET',           'due' => 'Period ends',       'bill' => 'Budget for'],
    'proforma'    => ['label' => 'Proforma Invoice', 'plural' => 'Proforma Invoices', 'nav' => 'Proforma invoices', 'prefix' => 'PRF', 'title' => 'PROFORMA INVOICE', 'due' => 'Valid until',       'bill' => 'Bill to'],
    'receipt'     => ['label' => 'Receipt',          'plural' => 'Receipts',          'prefix' => 'RCT', 'title' => 'RECEIPT',          'due' => '',                  'bill' => 'Received from'],
];
const CURRENCIES = ['UGX' => ['Uganda Shillings', 'Cents'], 'USD' => ['US Dollars', 'Cents'], 'EUR' => ['Euros', 'Cents'], 'GBP' => ['Pounds Sterling', 'Pence'], 'KES' => ['Kenya Shillings', 'Cents']];
const CONVERSIONS = ['requisition' => ['quotation'], 'quotation' => ['invoice', 'proforma'], 'proforma' => ['invoice'], 'invoice' => ['receipt'], 'budget' => [], 'receipt' => []];
/* Document permissions live in the panel's own roles system (admin/inc/perms.php): one module per document type
   ("doc_invoice.view", "doc_receipt.cancel" ...), plus "customers.*", "items.*", "doc_reports.view", "doc_settings.edit".
   dcan('doc.post', $admin, 'invoice') asks about one type; without a type it asks "on any type". */
const DOC_ACTIONS = ['doc.view' => 'view', 'doc.create' => 'add', 'doc.edit' => 'edit', 'doc.post' => 'post', 'doc.print' => 'print', 'doc.cancel' => 'cancel', 'doc.edit_posted' => 'edit_posted'];
const DOC_PERM_MAP = [
    'report.view' => 'doc_reports.view', 'customer.view' => 'customers.view', 'customer.add' => 'customers.add', 'customer.manage' => 'customers.edit',
    'product.view' => 'items.view', 'product.add' => 'items.add', 'product.manage' => 'items.edit',
];
const DOC_ICONS = ['requisition' => 'clipboard-check', 'quotation' => 'file-earmark-text', 'invoice' => 'receipt', 'budget' => 'piggy-bank', 'proforma' => 'file-earmark-ruled', 'receipt' => 'cash-coin'];
const MAX_LINES = 100;
const INVOICE_DUE_DAYS = 30;   // an invoice is always due 30 days after its date
const TERMS_DEFAULT = 'Kindly pay by the due date shown. Prices exclude any tax not listed. Goods remain the property of Audtix Systems Ltd until paid in full. Thank you for your business.';
const TERMS_OLD_DEFAULT = 'Payment is due by the date shown. Prices are in the currency shown and exclude any tax not listed. Goods remain the property of Audtix Systems Ltd until paid in full.';
function invoice_due_date(string $date): string { return (new DateTime($date))->modify('+' . INVOICE_DUE_DAYS . ' days')->format('Y-m-d'); }

/* ===================== permissions ===================== */
/** True if $admin (default: the signed-in admin) holds the permission $p (for document actions, on $type or on any type). */
function dcan(string $p, ?array $admin = null, ?string $type = null): bool {
    if (isset(DOC_ACTIONS[$p])) {
        if ($type === null) { foreach (array_keys(DOC_TYPES) as $t) if (dcan($p, $admin, $t)) return true; return false; }
        if (!isset(DOC_TYPES[$type])) return false;
        $key = 'doc_' . $type . '.' . DOC_ACTIONS[$p];
    } else $key = DOC_PERM_MAP[$p] ?? $p;
    $me = me();
    if ($admin === null || ($me && (int) $admin['id'] === (int) $me['id'])) return can($key);
    if (($admin['role'] ?? '') === 'super_admin') return true;
    if (empty($admin['role_id'])) return false;
    $s = db()->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ? AND permission = ?'); $s->execute([(int) $admin['role_id'], $key]);
    return (bool) $s->fetchColumn();
}
/** Document types the signed-in admin holds permission $p on. */
function doc_types_for(string $p = 'doc.view'): array { $o = []; foreach (array_keys(DOC_TYPES) as $t) if (dcan($p, null, $t)) $o[] = $t; return $o; }
/** SQL condition limiting $col to the types the signed-in admin may view (types are fixed, safe words). */
function doc_type_in(string $col = 'doc_type', string $p = 'doc.view'): string { $t = doc_types_for($p); return $t ? $col . " IN ('" . implode("','", $t) . "')" : '1=0'; }
/** Signed-in admin, or the panel's "no access" page. */
function doc_need(string $p, ?string $type = null): array {
    $a = require_login();
    if (!dcan($p, $a, $type)) {
        require_perm(isset(DOC_ACTIONS[$p]) ? 'doc_' . ($type ?? 'invoice') . '.' . DOC_ACTIONS[$p] : (DOC_PERM_MAP[$p] ?? $p));
        http_response_code(403); exit;
    }
    return $a;
}

/* ===================== settings ===================== */
function doc_settings(): array {
    static $s = null;
    if ($s === null) {
        $s = []; try { foreach (db()->query('SELECT skey, svalue FROM app_settings') as $r) $s[$r['skey']] = (string) $r['svalue']; } catch (Throwable $e) {}
        if (($s['terms_default'] ?? '') === TERMS_OLD_DEFAULT) {          // the original wording was never edited: switch to the shorter, politer one
            $s['terms_default'] = TERMS_DEFAULT;
            try { db()->prepare('UPDATE app_settings SET svalue = ?, updated_at = ? WHERE skey = ? AND svalue = ?')->execute([TERMS_DEFAULT, now(), 'terms_default', TERMS_OLD_DEFAULT]); } catch (Throwable $e) {}
        }
    }
    return $s;
}
function doc_setting(string $k, string $d = ''): string { $v = doc_settings()[$k] ?? ''; return $v !== '' ? $v : $d; }
function save_doc_setting(PDO $pdo, string $k, string $v): void {
    $u = $pdo->prepare('UPDATE app_settings SET svalue = ?, updated_at = ? WHERE skey = ?'); $u->execute([$v, now(), $k]);
    if ($u->rowCount() === 0) { $c = $pdo->prepare('SELECT COUNT(*) FROM app_settings WHERE skey = ?'); $c->execute([$k]); if (!(int) $c->fetchColumn()) $pdo->prepare('INSERT INTO app_settings (skey, svalue, updated_at) VALUES (?,?,?)')->execute([$k, $v, now()]); }
}

/* ===================== money (integer cents) ===================== */
function to_cents($v): ?int {
    $v = str_replace([',', ' '], '', trim((string) $v));
    if ($v === '') return null;
    if (!preg_match('/^(\d{1,13})(?:\.(\d{1,2}))?$/', $v, $m)) return null;
    return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');
}
function db_cents($v): int { return (int) round(((float) $v) * 100); }
function dec(int $c): string { return ($c < 0 ? '-' : '') . intdiv(abs($c), 100) . '.' . str_pad((string) (abs($c) % 100), 2, '0', STR_PAD_LEFT); }
function dmoney(int $c, string $cur = 'UGX'): string {
    $dp = ($cur === 'UGX' && $c % 100 === 0) ? 0 : 2;
    return number_format($c / 100, $dp, '.', ',');
}
function fmt_qty($q): string { $s = rtrim(rtrim(number_format((float) $q, 3, '.', ','), '0'), '.'); return $s === '' ? '0' : $s; }

function amount_in_words(int $cents, string $cur = 'UGX'): string {
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $scales = [1000000000000 => 'Trillion', 1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'];
    $below1000 = function (int $n) use ($ones, $tens): string {
        $w = '';
        if ($n >= 100) { $w .= $ones[intdiv($n, 100)] . ' Hundred'; $n %= 100; if ($n) $w .= ' and '; }
        if ($n >= 20) { $w .= $tens[intdiv($n, 10)]; if ($n % 10) $w .= '-' . $ones[$n % 10]; }
        elseif ($n > 0) $w .= $ones[$n];
        return $w;
    };
    $say = function (int $n) use ($scales, $below1000): string {
        if ($n === 0) return 'Zero';
        $parts = [];
        foreach ($scales as $v => $name) { if ($n >= $v) { $parts[] = $below1000(intdiv($n, $v)) . ' ' . $name; $n %= $v; } }
        if ($n > 0) { $tail = $below1000($n); $parts[] = (count($parts) && $n < 100 ? 'and ' : '') . $tail; }
        return implode(' ', $parts);
    };
    [$major, $minor] = CURRENCIES[$cur] ?? [$cur, 'Cents'];
    $whole = intdiv(abs($cents), 100); $frac = abs($cents) % 100;
    $s = ($cur === 'UGX' ? '' : $major . ' ') . $say($whole);
    if ($frac) $s .= ' and ' . $say($frac) . ' ' . ($frac === 1 && $minor === 'Cents' ? 'Cent' : $minor);
    return $s . ' Only';
}

/* ===================== calculation (server side, integer maths) ===================== */
/** @return array{lines:array,totals:array,errors:array} */
function calc_lines(array $input): array {
    $lines = []; $errors = []; $sub = 0; $disc = 0; $tax = 0; $total = 0; $n = 0;
    foreach (array_values($input) as $row) {
        if (!is_array($row)) continue;
        $desc = trim((string) ($row['description'] ?? ''));
        $qtyRaw = trim((string) ($row['quantity'] ?? '')); $priceRaw = trim((string) ($row['unit_price'] ?? ''));
        if ($desc === '' && $priceRaw === '' && ($qtyRaw === '' || $qtyRaw === '1')) continue;       // untouched blank row
        $n++; $label = 'Line ' . $n;
        if ($n > MAX_LINES) { $errors['lines'] = 'At most ' . MAX_LINES . ' lines.'; break; }
        if ($desc === '') { $errors['lines'] = "$label needs a description."; continue; }
        $q = str_replace(',', '', $qtyRaw === '' ? '1' : $qtyRaw);
        if (!preg_match('/^(\d{1,9})(?:\.(\d{1,3}))?$/', $q, $m) || (float) $q <= 0) { $errors['lines'] = "$label: quantity must be a number above zero."; continue; }
        $qm = (int) $m[1] * 1000 + (int) str_pad($m[2] ?? '0', 3, '0');
        $pc = $priceRaw === '' ? 0 : to_cents($priceRaw);
        if ($pc === null) { $errors['lines'] = "$label: unit price is not a valid amount."; continue; }
        $dc = trim((string) ($row['discount'] ?? '')) === '' ? 0 : to_cents($row['discount']);
        if ($dc === null) { $errors['lines'] = "$label: discount is not a valid amount."; continue; }
        $gross = intdiv($qm * $pc + 500, 1000);
        if ($dc > $gross) { $errors['lines'] = "$label: discount is larger than the line amount."; continue; }
        $rateRaw = str_replace(',', '', trim((string) ($row['tax_rate'] ?? '0'))); if ($rateRaw === '') $rateRaw = '0';
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $rateRaw) || (float) $rateRaw > 100) { $errors['lines'] = "$label: tax % must be between 0 and 100."; continue; }
        $bp = (int) round((float) $rateRaw * 100);
        $net = $gross - $dc; $tx = intdiv($net * $bp + 5000, 10000); $lt = $net + $tx;
        $lines[] = ['item_id' => ($row['item_id'] ?? '') !== '' ? (int) $row['item_id'] : null, 'description' => mb_substr($desc, 0, 1000), 'quantity' => $qm / 1000, 'qty_m' => $qm,
            'unit' => mb_substr(trim((string) ($row['unit'] ?? '')), 0, 30), 'unit_price' => $pc, 'gross' => $gross, 'discount' => $dc, 'tax_rate' => $bp / 100, 'tax' => $tx, 'total' => $lt];
        $sub += $gross; $disc += $dc; $tax += $tx; $total += $lt;
    }
    if (!$lines && !$errors) $errors['lines'] = 'Add at least one line.';
    return ['lines' => $lines, 'totals' => ['subtotal' => $sub, 'discount' => $disc, 'tax' => $tax, 'total' => $total], 'errors' => $errors];
}

/* ===================== numbering and audit ===================== */
function next_doc_no(PDO $pdo, string $type, int $year): string {
    for ($try = 0; $try < 4; $try++) {
        $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
        try {
            $u = $pdo->prepare('UPDATE doc_counters SET last_number = last_number + 1 WHERE doc_type = ? AND yr = ?'); $u->execute([$type, $year]);
            if ($u->rowCount() === 0) {
                try { $pdo->prepare('INSERT INTO doc_counters (doc_type, yr, last_number) VALUES (?,?,1)')->execute([$type, $year]); }
                catch (PDOException $e) { if ($own) $pdo->rollBack(); if ($own) continue; throw $e; }
            }
            $s = $pdo->prepare('SELECT last_number FROM doc_counters WHERE doc_type = ? AND yr = ?'); $s->execute([$type, $year]); $n = (int) $s->fetchColumn();
            if ($own) $pdo->commit();
            return DOC_TYPES[$type]['prefix'] . '-' . $year . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
        } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    throw new RuntimeException('Could not allocate a document number');
}
function doc_event(PDO $pdo, int $docId, ?int $adminId, string $type, string $detail = ''): void {
    $pdo->prepare('INSERT INTO document_events (document_id, admin_id, event_type, detail, ip, created_at) VALUES (?,?,?,?,?,?)')->execute([$docId, $adminId, $type, mb_substr($detail, 0, 600), client_ip(), now()]);
}

/* ===================== loading ===================== */
function get_document(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare('SELECT * FROM documents WHERE id = ?'); $s->execute([$id]); $d = $s->fetch();
    if (!$d) return null;
    $l = $pdo->prepare('SELECT * FROM document_lines WHERE document_id = ? ORDER BY line_no'); $l->execute([$id]); $d['lines'] = $l->fetchAll();
    return $d;
}
function invoice_paid(PDO $pdo, int $invoiceId, int $beforeReceiptId = 0): int {
    $q = "SELECT COALESCE(SUM(total), 0) FROM documents WHERE parent_id = ? AND doc_type = 'receipt' AND status = 'posted'" . ($beforeReceiptId ? ' AND id < ' . (int) $beforeReceiptId : '');
    $s = $pdo->prepare($q); $s->execute([$invoiceId]); return db_cents($s->fetchColumn());
}
/** Everything the printed document needs beyond the row itself. */
function doc_context(PDO $pdo, array $d): array {
    $cx = ['paid' => 0, 'balance' => 0, 'prev_balance' => 0, 'parent' => null, 'prepared_by' => '', 'authorized_by' => '', 'inv_total' => 0, 'inv_prior' => 0];
    foreach (['created_by' => 'prepared_by', 'posted_by' => 'authorized_by'] as $col => $key) {
        if (!empty($d[$col])) { $s = $pdo->prepare('SELECT full_name FROM admins WHERE id = ?'); $s->execute([$d[$col]]); $cx[$key] = (string) $s->fetchColumn(); }
    }
    if (!empty($d['parent_id'])) { $s = $pdo->prepare('SELECT id, doc_no, doc_type, total, status FROM documents WHERE id = ?'); $s->execute([$d['parent_id']]); $cx['parent'] = $s->fetch() ?: null; }
    $tot = db_cents($d['total']);
    if ($d['doc_type'] === 'invoice') {
        $cx['paid'] = invoice_paid($pdo, (int) $d['id']); $cx['balance'] = $tot - $cx['paid'];
        if ($d['status'] === 'posted' && !empty($d['customer_id'])) {
            $s = $pdo->prepare("SELECT d.id, d.total FROM documents d WHERE d.customer_id = ? AND d.doc_type = 'invoice' AND d.status = 'posted' AND d.id < ?"); $s->execute([$d['customer_id'], $d['id']]);
            $prev = 0; foreach ($s->fetchAll() as $o) $prev += max(0, db_cents($o['total']) - invoice_paid($pdo, (int) $o['id']));
            $cx['prev_balance'] = $prev;
        }
    }
    if ($d['doc_type'] === 'receipt' && $cx['parent'] && $cx['parent']['doc_type'] === 'invoice') {
        $cx['inv_total'] = db_cents($cx['parent']['total']);
        $cx['inv_prior'] = invoice_paid($pdo, (int) $cx['parent']['id'], (int) $d['id']);
        $cx['balance'] = $cx['inv_total'] - $cx['inv_prior'] - ($d['status'] === 'cancelled' ? 0 : $tot);
    }
    return $cx;
}

/* ===================== header validation ===================== */
function valid_date(string $s): bool { $d = DateTime::createFromFormat('Y-m-d', $s); return $d && $d->format('Y-m-d') === $s && (int) $d->format('Y') >= 2000 && (int) $d->format('Y') <= 2100; }
function fmt_date(?string $s): string { if (!$s) return ''; $t = DateTime::createFromFormat('Y-m-d', substr($s, 0, 10)); return $t ? $t->format('j F Y') : $s; }
function t(array $in, string $k, int $max): string { return mb_substr(trim((string) ($in[$k] ?? '')), 0, $max); }

/* ===================== create / edit ===================== */
/** Returns ['ok'=>true,'id'=>int] or ['ok'=>false,'errors'=>[field=>msg]] */
function save_document(PDO $pdo, array $admin, string $type, ?int $id, array $in): array {
    if (!isset(DOC_TYPES[$type])) return ['ok' => false, 'errors' => ['form' => 'Unknown document type.']];
    $err = [];
    $existing = null;
    if ($id) {
        $existing = get_document($pdo, $id);
        if (!$existing || $existing['doc_type'] !== $type) return ['ok' => false, 'errors' => ['form' => 'Document not found.']];
        $why = edit_block_reason($admin, $existing); if ($why) return ['ok' => false, 'errors' => ['form' => $why]];
    } elseif (!dcan('doc.create', $admin, $type)) return ['ok' => false, 'errors' => ['form' => 'You may not create this kind of document.']];

    $isPosted = $existing && $existing['status'] === 'posted';
    $editReason = t($in, 'edit_reason', 400);
    if ($isPosted && mb_strlen($editReason) < 5) $err['edit_reason'] = 'Give the reason for changing a posted document.';

    $date = t($in, 'doc_date', 10); if (!valid_date($date)) $err['doc_date'] = 'Choose a valid date.';
    $due = t($in, 'due_date', 10); if ($type === 'invoice' && valid_date($date)) $due = invoice_due_date($date);
    if ($due !== '' && !valid_date($due)) $err['due_date'] = 'Choose a valid date.'; if ($due !== '' && !isset($err['doc_date']) && !isset($err['due_date']) && $due < $date && $type !== 'receipt') $err['due_date'] = 'This cannot be before the document date.';
    $cur = strtoupper(t($in, 'currency', 3)) ?: doc_setting('default_currency', 'UGX'); if (!isset(CURRENCIES[$cur])) $err['currency'] = 'Choose a supported currency.';

    $cid = (int) ($in['customer_id'] ?? 0); $cust = null;
    if ($cid) { $s = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $s->execute([$cid]); $cust = $s->fetch() ?: null; }
    if (!$cust) $err['customer_id'] = 'Choose a customer.';
    elseif (!(int) $cust['is_active'] && !($existing && (int) $existing['customer_id'] === $cid)) $err['customer_id'] = 'This customer is switched off.';

    $parent = null; $parentId = null;
    if ($type === 'receipt') {
        $pid = (int) ($in['parent_id'] ?? 0);
        if ($pid) {
            $parent = get_document($pdo, $pid);
            if (!$parent || $parent['doc_type'] !== 'invoice') $err['parent_id'] = 'Choose a valid invoice.';
            elseif ($parent['status'] !== 'posted') $err['parent_id'] = 'The invoice must be posted before a receipt can be raised.';
            elseif ($cust && (int) $parent['customer_id'] !== (int) $cust['id']) $err['parent_id'] = 'That invoice belongs to a different customer.';
            else $parentId = $pid;
        }
    } elseif ($existing) $parentId = $existing['parent_id'] ? (int) $existing['parent_id'] : null;
    else {   // a new non-receipt can only point at a document it is genuinely converted from
        $pid = (int) ($in['parent_id'] ?? 0);
        if ($pid) { $pp = $pdo->prepare('SELECT doc_type FROM documents WHERE id = ?'); $pp->execute([$pid]); $pt = $pp->fetchColumn(); $parentId = ($pt && in_array($type, CONVERSIONS[$pt] ?? [], true)) ? $pid : null; }
    }

    $payMethod = t($in, 'payment_method', 60); $payRef = t($in, 'payment_ref', 120); $refText = t($in, 'reference_text', 190);
    if ($type === 'receipt') {
        if ($payMethod === '') $err['payment_method'] = 'Say how the payment was made.';
        if (!$parentId && $refText === '') $err['reference_text'] = 'Link an invoice or describe the transaction this receipt is for.';
        $amt = to_cents($in['amount'] ?? ''); if ($amt === null || $amt <= 0) $err['amount'] = 'Enter the amount received.';
        $desc = $parent ? 'Payment received against Invoice ' . $parent['doc_no'] : ('Payment received: ' . $refText);
        $calc = ['lines' => [], 'errors' => []];
        if (!isset($err['amount'])) $calc = calc_lines([['description' => $desc, 'quantity' => '1', 'unit_price' => dec($amt), 'tax_rate' => '0']]);
    } else $calc = calc_lines((array) ($in['lines'] ?? []));
    if (isset($calc['errors']['lines'])) $err['lines'] = $calc['errors']['lines'];
    if ($err) return ['ok' => false, 'errors' => $err];
    $tot = $calc['totals'];
    if ($tot['total'] <= 0 && $type !== 'budget') { return ['ok' => false, 'errors' => ['lines' => 'The total must be above zero.']]; }

    $pdo->beginTransaction();
    try {
        if ($parentId) { $pdo->prepare('UPDATE documents SET updated_at = updated_at WHERE id = ?')->execute([$parentId]); }   // row lock on the parent
        if ($type === 'receipt' && $parent) {
            $paid = invoice_paid($pdo, (int) $parent['id']);
            if ($existing && $existing['status'] === 'posted' && (int) $existing['parent_id'] === (int) $parent['id']) $paid -= db_cents($existing['total']);
            $bal = db_cents($parent['total']) - $paid;
            if ($tot['total'] > $bal) { $pdo->rollBack(); return ['ok' => false, 'errors' => ['amount' => 'This is more than the invoice balance (' . dmoney($bal, $parent['currency']) . ' ' . $parent['currency'] . ').']]; }
        }
        if ($existing && $existing['doc_type'] === 'invoice' && $isPosted) {
            $paid = invoice_paid($pdo, (int) $existing['id']);
            if ($tot['total'] < $paid) { $pdo->rollBack(); return ['ok' => false, 'errors' => ['lines' => 'The new total is below the ' . dmoney($paid, $existing['currency']) . ' already received on this invoice.']]; }
            if ($paid > 0 && (int) $existing['customer_id'] !== (int) $cust['id']) { $pdo->rollBack(); return ['ok' => false, 'errors' => ['customer_id' => 'Receipts exist for this invoice, so the customer cannot change.']]; }
        }
        $snap = [$cust['name'], $cust['contact_person'], $cust['tin'], $cust['address'], $cust['phone'], $cust['email'], $cust['logo_file']];
        if ($existing && (int) $existing['customer_id'] === (int) $cust['id'] && $isPosted) {      // a posted document keeps the details it was issued with
            $snap = [$existing['customer_name'], $existing['customer_contact'], $existing['customer_tin'], $existing['customer_address'], $existing['customer_phone'], $existing['customer_email'], $existing['customer_logo']];
        }
        $vals = [$cust['id'], $snap[0], $snap[1] ?: null, $snap[2] ?: null, $snap[3] ?: null, $snap[4] ?: null, $snap[5] ?: null, $snap[6] ?: null, $date, $due ?: null, $cur,
                 $payMethod ?: null, $payRef ?: null, t($in, 'salesperson', 120) ?: null, $refText ?: null, $parentId, dec($tot['subtotal']), dec($tot['discount']), dec($tot['tax']), dec($tot['total']),
                 t($in, 'notes', 2000) ?: null, t($in, 'terms', 4000) ?: null];
        if (!$existing) {
            $no = next_doc_no($pdo, $type, (int) (new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('Y'));
            $pdo->prepare('INSERT INTO documents (doc_type, doc_no, status, customer_id, customer_name, customer_contact, customer_tin, customer_address, customer_phone, customer_email, customer_logo, doc_date, due_date, currency, payment_method, payment_ref, salesperson, reference_text, parent_id, subtotal, discount_total, tax_total, total, notes, terms, version, created_by, updated_by, created_at, updated_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute(array_merge([$type, $no, 'draft'], $vals, [1, $admin['id'], $admin['id'], now(), now()]));
            $docId = (int) $pdo->lastInsertId();
            doc_event($pdo, $docId, (int) $admin['id'], 'created', 'Created ' . DOC_TYPES[$type]['label'] . ' ' . $no . ' as a draft'); log_activity((int) $admin['id'], 'document_created', (string) $no, DOC_TYPES[$type]['label']);
        } else {
            $docId = (int) $existing['id'];
            if ($existing['status'] !== 'draft') {
                $pdo->prepare('INSERT INTO document_revisions (document_id, version, snapshot_json, admin_id, created_at) VALUES (?,?,?,?,?)')->execute([$docId, $existing['version'], json_encode($existing, JSON_UNESCAPED_UNICODE), $admin['id'], now()]);
            }
            $u = $pdo->prepare('UPDATE documents SET customer_id=?, customer_name=?, customer_contact=?, customer_tin=?, customer_address=?, customer_phone=?, customer_email=?, customer_logo=?, doc_date=?, due_date=?, currency=?, payment_method=?, payment_ref=?, salesperson=?, reference_text=?, parent_id=?, subtotal=?, discount_total=?, tax_total=?, total=?, notes=?, terms=?, version = version + 1, updated_by=?, updated_at=? WHERE id=? AND version=? AND status <> ?');
            $u->execute(array_merge($vals, [$admin['id'], now(), $docId, (int) ($in['version'] ?? $existing['version']), 'cancelled']));
            if ($u->rowCount() === 0) { $pdo->rollBack(); return ['ok' => false, 'errors' => ['form' => 'Someone else changed this document while you were editing. Reload it and try again.']]; }
            $pdo->prepare('DELETE FROM document_lines WHERE document_id = ?')->execute([$docId]);
            if ($isPosted) { log_activity((int) $admin['id'], 'document_revised', (string) $existing['doc_no'], $editReason); doc_event($pdo, $docId, (int) $admin['id'], 'revised', 'Posted document changed. Reason: ' . $editReason . '. Total ' . dmoney(db_cents($existing['total']), $existing['currency']) . ' to ' . dmoney($tot['total'], $cur)); log_activity((int) $admin['id'], 'document_revised', $existing['doc_no'], $editReason); }
            else doc_event($pdo, $docId, (int) $admin['id'], 'edited', 'Draft edited');
        }
        $li = $pdo->prepare('INSERT INTO document_lines (document_id, line_no, item_id, description, quantity, unit, unit_price, gross_amount, discount, tax_rate, tax_amount, line_total) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($calc['lines'] as $i => $l) {
            $itemId = null;
            if ($l['item_id']) { $c = $pdo->prepare('SELECT COUNT(*) FROM items WHERE id = ?'); $c->execute([$l['item_id']]); if ((int) $c->fetchColumn()) $itemId = $l['item_id']; }
            $li->execute([$docId, $i + 1, $itemId, $l['description'], $l['qty_m'] / 1000, $l['unit'] ?: null, dec($l['unit_price']), dec($l['gross']), dec($l['discount']), $l['tax_rate'], dec($l['tax']), dec($l['total'])]);
        }
        $pdo->commit();
        return ['ok' => true, 'id' => $docId];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('save_document: ' . $e->getMessage()); return ['ok' => false, 'errors' => ['form' => 'The document could not be saved. Please try again.']]; }
}

/** Why this admin may not edit this document, or '' when they may. */
function edit_block_reason(array $admin, array $d): string {
    if ($d['status'] === 'cancelled') return 'A cancelled document cannot be changed.';
    $super = $admin['role'] === 'super_admin';
    if ($d['status'] === 'draft') return dcan('doc.edit', $admin, $d['doc_type']) ? '' : 'You may not edit this kind of document.';
    if ($d['doc_type'] === 'receipt') return $super ? '' : 'A posted receipt can only be changed by the super admin. Cancel it with a reason and raise a new one instead.';
    if ($super || dcan('doc.edit_posted', $admin, $d['doc_type'])) return '';
    return 'This document is posted and locked. Only a super admin, or someone allowed to edit posted documents, can change it.';
}

/* ===================== post / cancel / convert ===================== */
function post_document(PDO $pdo, array $admin, int $id): array {
    $d0 = get_document($pdo, $id);
    if (!$d0 || !dcan('doc.post', $admin, $d0['doc_type'])) return ['ok' => false, 'error' => 'You may not post this kind of document.'];
    $pdo->beginTransaction();
    try {
        $d = get_document($pdo, $id);
        if (!$d) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Document not found.']; }
        if ($d['status'] !== 'draft') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Only a draft can be posted.']; }
        if (!$d['lines'] || db_cents($d['total']) <= 0 && $d['doc_type'] !== 'budget') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Add lines with a total above zero before posting.']; }
        if ($d['doc_type'] === 'receipt') {
            if (trim((string) $d['payment_method']) === '') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Open the draft and say how the payment was made before posting.']; }
            if ($d['parent_id']) {
                $pdo->prepare('UPDATE documents SET updated_at = updated_at WHERE id = ?')->execute([$d['parent_id']]);
                $p = get_document($pdo, (int) $d['parent_id']);
                if (!$p || $p['status'] !== 'posted') { $pdo->rollBack(); return ['ok' => false, 'error' => 'The invoice is no longer posted, so this receipt cannot be posted.']; }
                if (db_cents($d['total']) > db_cents($p['total']) - invoice_paid($pdo, (int) $p['id'])) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Other receipts have been posted since this draft was made. It is now above the invoice balance.']; }
            }
        }
        $u = $pdo->prepare("UPDATE documents SET status = 'posted', posted_by = ?, posted_at = ?, version = version + 1, updated_at = ? WHERE id = ? AND status = 'draft'");
        $u->execute([$admin['id'], now(), now(), $id]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return ['ok' => false, 'error' => 'This document was changed by someone else.']; }
        doc_event($pdo, $id, (int) $admin['id'], 'posted', 'Posted by ' . $admin['full_name']);
        $pdo->commit(); log_activity((int) $admin['id'], 'document_posted', $d['doc_no']);
        $d['status'] = 'posted'; rfq_mark_responded($pdo, $d, (int) $admin['id']);
        return ['ok' => true];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('post_document: ' . $e->getMessage()); return ['ok' => false, 'error' => 'Could not post the document.']; }
}

function cancel_document(PDO $pdo, array $admin, int $id, string $reason): array {
    $d0 = get_document($pdo, $id);
    if (!$d0 || !dcan('doc.cancel', $admin, $d0['doc_type'])) return ['ok' => false, 'error' => 'You may not cancel or void this kind of document.'];
    $reason = mb_substr(trim($reason), 0, 500);
    if (mb_strlen($reason) < 5) return ['ok' => false, 'error' => 'Give a reason of at least 5 characters.'];
    $pdo->beginTransaction();
    try {
        $d = get_document($pdo, $id);
        if (!$d) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Document not found.']; }
        if ($d['status'] === 'cancelled') { $pdo->rollBack(); return ['ok' => false, 'error' => 'Already cancelled.']; }
        if ($d['doc_type'] === 'invoice' && invoice_paid($pdo, $id) > 0) { $pdo->rollBack(); return ['ok' => false, 'error' => 'This invoice has posted receipts. Cancel the receipts first.']; }
        $u = $pdo->prepare("UPDATE documents SET status = 'cancelled', cancelled_by = ?, cancelled_at = ?, cancel_reason = ?, version = version + 1, updated_at = ? WHERE id = ? AND status <> 'cancelled'");
        $u->execute([$admin['id'], now(), $reason, now(), $id]);
        if ($u->rowCount() !== 1) { $pdo->rollBack(); return ['ok' => false, 'error' => 'Already cancelled.']; }
        doc_event($pdo, $id, (int) $admin['id'], 'cancelled', 'Cancelled (' . $d['status'] . '). Reason: ' . $reason);
        $pdo->commit(); log_activity((int) $admin['id'], 'document_cancelled', $d['doc_no'], $reason);
        return ['ok' => true];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); return ['ok' => false, 'error' => 'Could not cancel the document.']; }
}

function convert_document(PDO $pdo, array $admin, int $id, string $target): array {
    $src = get_document($pdo, $id);
    if (!$src || !dcan('doc.view', $admin, $src['doc_type'])) return ['ok' => false, 'error' => 'Document not found.'];
    if (!dcan('doc.create', $admin, $target)) return ['ok' => false, 'error' => 'You may not create this kind of document.'];
    if (!in_array($target, CONVERSIONS[$src['doc_type']] ?? [], true)) return ['ok' => false, 'error' => 'This cannot be converted that way.'];
    if ($src['status'] !== 'posted') return ['ok' => false, 'error' => 'Post (approve) the ' . strtolower(DOC_TYPES[$src['doc_type']]['label']) . ' before converting it.'];
    $s = $pdo->prepare("SELECT doc_no FROM documents WHERE parent_id = ? AND doc_type = ? AND status <> 'cancelled' LIMIT 1"); $s->execute([$id, $target]);
    if ($target !== 'receipt' && ($ex = $s->fetchColumn())) return ['ok' => false, 'error' => 'Already converted: ' . $ex . '. Cancel it first if you need to convert again.'];
    $in = ['customer_id' => $src['customer_id'], 'doc_date' => (new DateTime('now', new DateTimeZone('Africa/Kampala')))->format('Y-m-d'), 'currency' => $src['currency'], 'salesperson' => $src['salesperson'], 'reference_text' => $src['reference_text'] ?: '', 'notes' => $src['notes'], 'terms' => $src['terms'] ?: doc_setting('terms_default'), 'parent_id' => $id];
    $days = (int) doc_setting('quote_valid_days', '30');
    if ($target === 'invoice') $in['due_date'] = invoice_due_date($in['doc_date']);
    elseif ($target !== 'receipt') $in['due_date'] = (new DateTime($in['doc_date']))->modify("+$days days")->format('Y-m-d');
    if ($target === 'receipt') {
        $bal = db_cents($src['total']) - invoice_paid($pdo, $id);
        if ($bal <= 0) return ['ok' => false, 'error' => 'This invoice is already fully paid.'];
        $in['amount'] = dec($bal); $in['payment_method'] = 'x'; $in['reference_text'] = '';
    } else {
        $in['lines'] = array_map(fn($l) => ['item_id' => $l['item_id'], 'description' => $l['description'], 'quantity' => (string) $l['quantity'], 'unit' => $l['unit'], 'unit_price' => (string) $l['unit_price'], 'discount' => (string) $l['discount'], 'tax_rate' => (string) $l['tax_rate']], $src['lines']);
    }
    $r = save_document($pdo, $admin, $target, null, $in);
    if (!$r['ok']) return ['ok' => false, 'error' => implode(' ', $r['errors'])];
    if ($target === 'receipt') $pdo->prepare('UPDATE documents SET payment_method = NULL WHERE id = ?')->execute([$r['id']]);
    $new = get_document($pdo, (int) $r['id']);
    // carry the customer details exactly as issued on the source document
    $pdo->prepare('UPDATE documents SET customer_name=?, customer_contact=?, customer_tin=?, customer_address=?, customer_phone=?, customer_email=?, customer_logo=? WHERE id=?')
        ->execute([$src['customer_name'], $src['customer_contact'], $src['customer_tin'], $src['customer_address'], $src['customer_phone'], $src['customer_email'], $src['customer_logo'], $r['id']]);
    doc_event($pdo, (int) $r['id'], (int) $admin['id'], 'converted', 'Created from ' . DOC_TYPES[$src['doc_type']]['label'] . ' ' . $src['doc_no']);
    doc_event($pdo, $id, (int) $admin['id'], 'converted', 'Converted to ' . DOC_TYPES[$target]['label'] . ' ' . $new['doc_no']);
    log_activity((int) $admin['id'], 'document_converted', $src['doc_no'], '-> ' . $new['doc_no']);
    return ['ok' => true, 'id' => (int) $r['id']];
}

/* ===================== listing ===================== */
function list_documents(PDO $pdo, array $f, int $page = 1, int $per = 25): array {
    $w = [doc_type_in('d.doc_type')]; $a = [];
    if (!empty($f['type']) && isset(DOC_TYPES[$f['type']])) { $w[] = 'd.doc_type = ?'; $a[] = $f['type']; }
    if (!empty($f['status']) && in_array($f['status'], ['draft', 'posted', 'cancelled'], true)) { $w[] = 'd.status = ?'; $a[] = $f['status']; }
    if (!empty($f['customer_id'])) { $w[] = 'd.customer_id = ?'; $a[] = (int) $f['customer_id']; }
    if (!empty($f['created_by'])) { $w[] = 'd.created_by = ?'; $a[] = (int) $f['created_by']; }
    if (!empty($f['from']) && valid_date($f['from'])) { $w[] = 'd.doc_date >= ?'; $a[] = $f['from']; }
    if (!empty($f['to']) && valid_date($f['to'])) { $w[] = 'd.doc_date <= ?'; $a[] = $f['to']; }
    if (!empty($f['q'])) { $like = '%' . trim($f['q']) . '%'; $w[] = '(d.doc_no LIKE ? OR d.customer_name LIKE ? OR d.reference_text LIKE ?)'; array_push($a, $like, $like, $like); }
    $ws = $w ? 'WHERE ' . implode(' AND ', $w) : '';
    $c = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(d.total),0) FROM documents d $ws"); $c->execute($a); [$total, $sum] = array_values($c->fetch(PDO::FETCH_NUM));
    $pages = max(1, (int) ceil($total / $per)); $page = max(1, min($page, $pages)); $off = ($page - 1) * $per;
    $q = $pdo->prepare("SELECT d.*, a.full_name AS creator, (SELECT COALESCE(SUM(r.total),0) FROM documents r WHERE r.parent_id = d.id AND r.doc_type = 'receipt' AND r.status = 'posted') AS paid
                        FROM documents d LEFT JOIN admins a ON a.id = d.created_by $ws ORDER BY d.id DESC LIMIT " . (int) $per . ' OFFSET ' . (int) $off);
    $q->execute($a);
    return ['rows' => $q->fetchAll(), 'total' => (int) $total, 'sum' => db_cents($sum), 'page' => $page, 'pages' => $pages];
}

/* ===================== periods (East Africa Time) ===================== */
function period_range(string $p, string $from = '', string $to = ''): array {
    $now = new DateTime('now', new DateTimeZone('Africa/Kampala')); $y = (int) $now->format('Y'); $m = (int) $now->format('n');
    switch ($p) {
        case 'today':   return [$now->format('Y-m-d'), $now->format('Y-m-d'), 'Today'];
        case 'week':    $s = (clone $now)->modify('monday this week'); $e = (clone $s)->modify('+6 days'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'This week'];
        case 'quarter': $qs = intdiv($m - 1, 3) * 3 + 1; $s = new DateTime(sprintf('%d-%02d-01', $y, $qs)); $e = (clone $s)->modify('+3 months -1 day'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'This quarter'];
        case 'year':    return ["$y-01-01", "$y-12-31", 'This year'];
        case 'lastmonth':   $s = new DateTime(sprintf('%d-%02d-01', $y, $m)); $s->modify('-1 month'); $e = (clone $s)->modify('+1 month -1 day'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'Last month'];
        case 'lastquarter': $qs = intdiv($m - 1, 3) * 3 + 1; $s = new DateTime(sprintf('%d-%02d-01', $y, $qs)); $s->modify('-3 months'); $e = (clone $s)->modify('+3 months -1 day'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'Last quarter'];
        case 'lastyear':    return [($y - 1) . '-01-01', ($y - 1) . '-12-31', 'Last year'];
        case 'custom':  $f = valid_date($from) ? $from : "$y-01-01"; $t = valid_date($to) ? $to : $now->format('Y-m-d'); if ($t < $f) [$f, $t] = [$t, $f]; return [$f, $t, 'Custom range'];
        default:        $s = new DateTime(sprintf('%d-%02d-01', $y, $m)); $e = (clone $s)->modify('+1 month -1 day'); return [$s->format('Y-m-d'), $e->format('Y-m-d'), 'This month'];
    }
}

/* ===================== logos ===================== */
function logo_dir(): string { $d = storage_dir() . '/logos'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/** Validates an uploaded image, re-encodes it (strips metadata and any hidden payload) and returns [filename|null, error]. */
function save_logo(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, ''];
    if ($file['error'] !== UPLOAD_ERR_OK) return [null, 'The logo could not be uploaded.'];
    if ($file['size'] > 2 * 1024 * 1024) return [null, 'The logo must be under 2 MB.'];
    if (!function_exists('imagecreatefromstring')) return [null, 'Image support (GD) is not available on this server.'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) return [null, 'The logo must be a PNG, JPG, GIF or WebP image.'];
    if ($info[0] > 5000 || $info[1] > 5000 || $info[0] < 16 || $info[1] < 16) return [null, 'The logo size is not supported (16 to 5000 pixels).'];
    $im = @imagecreatefromstring((string) file_get_contents($file['tmp_name'])); if (!$im) return [null, 'The image could not be read.'];
    $w = imagesx($im); $h = imagesy($im); $max = 600;
    if ($w > $max) { $nh = (int) round($h * $max / $w); $n = imagecreatetruecolor($max, $nh); imagealphablending($n, false); imagesavealpha($n, true); imagefill($n, 0, 0, imagecolorallocatealpha($n, 255, 255, 255, 127)); imagecopyresampled($n, $im, 0, 0, 0, 0, $max, $nh, $w, $h); imagedestroy($im); $im = $n; }
    else { imagealphablending($im, false); imagesavealpha($im, true); }
    $name = bin2hex(random_bytes(12)) . '.png';
    $ok = @imagepng($im, logo_dir() . '/' . $name, 6); imagedestroy($im);
    return $ok ? [$name, ''] : [null, 'The logo could not be stored.'];
}
function company_logo_path(): string {
    $f = doc_setting('company_logo'); if ($f && preg_match('/^[a-f0-9]{24}\.png$/', $f) && is_file(logo_dir() . '/' . $f)) return logo_dir() . '/' . $f;
    return dirname(__DIR__, 2) . '/img/logo-blue.png';
}
function company_logo_url(): string { $f = doc_setting('company_logo'); return ($f && preg_match('/^[a-f0-9]{24}\.png$/', $f)) ? 'logo.php?f=' . $f : '../img/logo-blue.png'; }
function company_lines(): array {
    return array_values(array_filter([doc_setting('company_address'), trim(doc_setting('company_phone') . '   ' . doc_setting('company_email')), doc_setting('company_web'), doc_setting('company_tin') ? 'TIN: ' . doc_setting('company_tin') : '']));
}
function logo_url(?string $f): string { return $f && preg_match('/^[a-f0-9]{24}\.png$/', $f) ? 'logo.php?f=' . $f : ''; }

/* ===================== keeping requests (RFQs) up to date ===================== */
/** A posted quotation / proforma / invoice that carries an RFQ's reference moves that RFQ along:
    quotation or proforma posted = Responded (Quoted for tax/audit requests); invoice posted = Won (In progress for tax/audit). */
function rfq_mark_responded(PDO $pdo, array $d, ?int $adminId): void {
    if (!in_array($d['doc_type'], ['quotation', 'proforma', 'invoice'], true) || $d['status'] !== 'posted') return;
    $ref = trim((string) ($d['reference_text'] ?? '')); if ($ref === '') return;
    $won = $d['doc_type'] === 'invoice'; $label = DOC_TYPES[$d['doc_type']]['label'] . ' ' . $d['doc_no'];
    try {
        $s = $pdo->prepare('SELECT id, status, quoted_amount FROM quotes WHERE quote_no = ?'); $s->execute([$ref]);
        if ($q = $s->fetch()) {
            $new = $won ? 'approved' : 'responded';
            if (($won && in_array($q['status'], ['pending', 'in_review', 'responded'], true)) || (!$won && in_array($q['status'], ['pending', 'in_review'], true))) {
                $pdo->prepare('UPDATE quotes SET status = ?, quoted_amount = COALESCE(quoted_amount, ?), currency = COALESCE(currency, ?), valid_until = COALESCE(valid_until, ?), responded_at = COALESCE(responded_at, ?), responded_by = COALESCE(responded_by, ?), updated_at = ? WHERE id = ?')
                    ->execute([$new, $d['total'], $d['currency'], $d['due_date'] ?: null, now(), $adminId, now(), $q['id']]);
                quote_event($pdo, (int) $q['id'], $adminId, 'status', $label . ' was posted: request marked as ' . QUOTE_STATUSES[$new]);
            }
            return;
        }
        $s = $pdo->prepare('SELECT id, status FROM requests WHERE request_id = ?'); $s->execute([$ref]);
        if ($r = $s->fetch()) {
            $new = $won ? 'in_progress' : 'quoted';
            if (in_array($r['status'], $won ? ['new', 'in_review', 'awaiting_client', 'quoted'] : ['new', 'in_review', 'awaiting_client'], true)) {
                $pdo->prepare('UPDATE requests SET status = ?, updated_at = ? WHERE id = ?')->execute([$new, now(), $r['id']]);
                $pdo->prepare('INSERT INTO request_events (request_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')->execute([$r['id'], $adminId, 'status', $label . ' was posted: request marked as ' . (STATUSES[$new] ?? $new), now()]);
            }
        }
    } catch (Throwable $e) { error_log('rfq_mark_responded: ' . $e->getMessage()); }
}
/** Brings older RFQs in line with documents posted before this existed (safe to run any time). */
function rfq_sync_all(PDO $pdo): void {
    try {
        $rows = $pdo->query("SELECT d.* FROM documents d WHERE d.status = 'posted' AND d.doc_type IN ('quotation','proforma','invoice') AND d.reference_text IS NOT NULL AND d.reference_text <> ''
            AND (EXISTS (SELECT 1 FROM quotes q WHERE q.quote_no = d.reference_text AND q.status IN ('pending','in_review','responded')) OR EXISTS (SELECT 1 FROM requests r WHERE r.request_id = d.reference_text AND r.status IN ('new','in_review','awaiting_client','quoted'))) ORDER BY d.id LIMIT 200")->fetchAll();
        foreach ($rows as $d) rfq_mark_responded($pdo, $d, $d['posted_by'] ? (int) $d['posted_by'] : null);
    } catch (Throwable $e) { error_log('rfq_sync_all: ' . $e->getMessage()); }
}

/* ===================== service picker for document lines ===================== */
/** Audtix's own services, as shown on the website; offered first in the line-item dropdown. */
function doc_service_catalogue(): array {
    $o = [];
    try { foreach (quote_services() as $svc) if (!empty($svc['title'])) $o[] = (string) $svc['title']; } catch (Throwable $e) {}
    foreach (['Tax services', 'Audit services'] as $x) if (!in_array($x, $o, true)) $o[] = $x;
    return $o;
}
/** Active products and services from the price list (so their price, unit and tax fill in). */
function doc_price_list(PDO $pdo): array {
    $o = [];
    try {
        foreach ($pdo->query('SELECT id, item_type, name, description, unit, unit_price, tax_rate FROM items WHERE is_active = 1 ORDER BY name LIMIT 300')->fetchAll() as $r)
            $o[] = ['id' => (int) $r['id'], 'type' => $r['item_type'], 'name' => $r['name'], 'description' => $r['description'] ?: $r['name'], 'unit' => $r['unit'], 'price' => rtrim(rtrim(number_format((float) $r['unit_price'], 2, '.', ''), '0'), '.'), 'tax' => rtrim(rtrim(number_format((float) $r['tax_rate'], 2, '.', ''), '0'), '.')];
    } catch (Throwable $e) {}
    return $o;
}

/* ===================== safety net for the document pages ===================== */
/* A slow or failing query must never leave the browser waiting for a gateway timeout or show a blank "page isn't working":
   the page stops after a short time, the real error goes to the server log, and a super admin sees the technical reason. */
function doc_guard(): void {
    static $on = false; if ($on) return; $on = true;
    @set_time_limit(40);
    try {
        $pdo = db();
        if (str_starts_with((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME), 'mysql')) { $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10'); }
    } catch (Throwable $e) { /* the page itself reports a missing database */ }
    set_exception_handler(function (Throwable $e): void {
        error_log('documents: ' . get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
        if (!headers_sent()) http_response_code(500);
        $m = me(); $detail = ($m && ($m['role'] ?? '') === 'super_admin') ? get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '';
        $missing = $e instanceof PDOException && (str_contains($e->getMessage(), "doesn't exist") || str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), 'Unknown column'));
        echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong</title><body style="font-family:Arial,sans-serif;max-width:640px;margin:12vh auto;padding:0 18px;color:#10202F"><h2>Something went wrong</h2><p>'
            . ($missing ? 'The database is missing part of the business documents setup. A super admin can add it with one click (nothing existing is changed).' : 'The page could not finish. Nothing was lost; go back and try again.')
            . '</p>' . ($missing && $m && ($m['role'] ?? '') === 'super_admin' ? '<p><a href="doc-setup.php" style="display:inline-block;background:#1664C0;color:#fff;padding:9px 16px;border-radius:8px;text-decoration:none;font-weight:700">Set up the database now</a></p>' : '') . ($detail !== '' ? '<p style="background:#F1F5FA;padding:10px 12px;border-radius:8px;font:13px monospace;word-break:break-word">' . h($detail) . '</p>' : '') . '<p><a href="index.php">Back to the dashboard</a></p></body>';
    });
}
doc_guard();
