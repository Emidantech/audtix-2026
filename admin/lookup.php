<?php
/* JSON search used by the document editor: customers, items, open invoices. */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
header('Content-Type: application/json; charset=utf-8');
$admin = me();
if (!$admin || !(can('doc.create', $admin) || can('doc.edit', $admin) || can('doc.view', $admin))) { http_response_code(403); echo '{"rows":[]}'; exit; }
$pdo = db(); $k = $_GET['kind'] ?? ''; $q = trim((string) ($_GET['q'] ?? '')); $rows = [];
$like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
if ($k === 'customer') {
    $s = $pdo->prepare('SELECT id, name, contact_person, tin, address, phone, email, logo_file FROM customers WHERE is_active = 1 AND (name LIKE ? OR tin LIKE ? OR phone LIKE ? OR email LIKE ?) ORDER BY name LIMIT 12'); $s->execute([$like, $like, $like, $like]);
    foreach ($s->fetchAll() as $r) $rows[] = ['id' => (int) $r['id'], 'name' => $r['name'], 'contact' => $r['contact_person'], 'tin' => $r['tin'], 'address' => $r['address'], 'phone' => $r['phone'], 'email' => $r['email'], 'logo' => logo_url($r['logo_file'])];
} elseif ($k === 'item') {
    $s = $pdo->prepare('SELECT id, item_type, name, description, category, unit, unit_price, tax_rate FROM items WHERE is_active = 1 AND (name LIKE ? OR description LIKE ? OR category LIKE ?) ORDER BY name LIMIT 12'); $s->execute([$like, $like, $like]);
    foreach ($s->fetchAll() as $r) $rows[] = ['id' => (int) $r['id'], 'type' => $r['item_type'], 'name' => $r['name'], 'description' => $r['description'] ?: $r['name'], 'category' => $r['category'], 'unit' => $r['unit'], 'price' => rtrim(rtrim(number_format((float) $r['unit_price'], 2, '.', ''), '0'), '.'), 'tax' => rtrim(rtrim(number_format((float) $r['tax_rate'], 2, '.', ''), '0'), '.')];
} elseif ($k === 'invoice') {
    $cid = (int) ($_GET['customer_id'] ?? 0); $inc = (int) ($_GET['include'] ?? 0); $rid = (int) ($_GET['receipt'] ?? 0);
    $s = $pdo->prepare("SELECT id, doc_no, doc_date, total, currency FROM documents WHERE doc_type = 'invoice' AND status = 'posted' AND customer_id = ? ORDER BY id DESC LIMIT 60"); $s->execute([$cid]);
    foreach ($s->fetchAll() as $r) {
        $paid = invoice_paid($pdo, (int) $r['id']);
        if ($rid && (int) $r['id'] === $inc) { $x = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM documents WHERE id = ? AND status = 'posted' AND parent_id = ?"); $x->execute([$rid, $r['id']]); $paid -= db_cents($x->fetchColumn()); }
        $bal = db_cents($r['total']) - $paid;
        if ($bal > 0 || (int) $r['id'] === $inc) $rows[] = ['id' => (int) $r['id'], 'no' => $r['doc_no'], 'date' => fmt_date($r['doc_date']), 'total' => money(db_cents($r['total']), $r['currency']), 'balance' => dec(max(0, $bal)), 'balance_fmt' => money(max(0, $bal), $r['currency']), 'currency' => $r['currency']];
    }
}
echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
