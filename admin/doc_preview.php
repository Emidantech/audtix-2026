<?php
/* Shows what a document will look like from the editor's current (unsaved) values. Nothing is stored. */
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_once __DIR__ . '/inc/render.php';
$admin = require_login(); $pdo = db();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('dashboard.php');
$type = (string) ($_POST['type'] ?? ''); if (!isset(DOC_TYPES[$type]) || !(dcan('doc.create', $admin, $type) || dcan('doc.edit', $admin, $type))) { http_response_code(403); exit('Not allowed.'); }
$GLOBALS['nav_type'] = $type;
$s = $pdo->prepare('SELECT * FROM customers WHERE id = ?'); $s->execute([(int) ($_POST['customer_id'] ?? 0)]); $c = $s->fetch();
if ($type === 'receipt') {
    $amt = to_cents($_POST['amount'] ?? '') ?? 0; $calc = calc_lines([['description' => 'Payment received', 'quantity' => '1', 'unit_price' => dec($amt)]]);
} else $calc = calc_lines((array) ($_POST['lines'] ?? []));
dm_head('Preview', $admin, 'docs:' . $type); echo '<link rel="stylesheet" href="doc.css">';
if (!$c) { echo '<div class="flash bad">Choose a customer to see the preview.</div>'; dm_foot(); exit; }
if ($calc['errors']) { echo '<div class="flash bad">' . h(implode(' ', $calc['errors'])) . '</div>'; dm_foot(); exit; }
$t = $calc['totals'];
$d = ['id' => 0, 'doc_type' => $type, 'doc_no' => '(number given when saved)', 'status' => 'draft', 'customer_id' => $c['id'], 'customer_name' => $c['name'], 'customer_contact' => $c['contact_person'], 'customer_tin' => $c['tin'], 'customer_address' => $c['address'], 'customer_phone' => $c['phone'], 'customer_email' => $c['email'], 'customer_logo' => $c['logo_file'],
      'doc_date' => valid_date((string) ($_POST['doc_date'] ?? '')) ? $_POST['doc_date'] : gmdate('Y-m-d'), 'due_date' => valid_date((string) ($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null, 'currency' => isset(CURRENCIES[$_POST['currency'] ?? '']) ? $_POST['currency'] : 'UGX',
      'payment_method' => t($_POST, 'payment_method', 60), 'payment_ref' => t($_POST, 'payment_ref', 120), 'salesperson' => t($_POST, 'salesperson', 120), 'reference_text' => t($_POST, 'reference_text', 190), 'parent_id' => null,
      'subtotal' => dec($t['subtotal']), 'discount_total' => dec($t['discount']), 'tax_total' => dec($t['tax']), 'total' => dec($t['total']), 'notes' => t($_POST, 'notes', 2000), 'terms' => t($_POST, 'terms', 4000), 'created_by' => $admin['id'], 'posted_by' => null, 'cancelled_at' => null, 'cancel_reason' => null, 'lines' => []];
foreach ($calc['lines'] as $l) $d['lines'][] = ['description' => $l['description'], 'quantity' => $l['quantity'], 'unit' => $l['unit'], 'unit_price' => dec($l['unit_price']), 'discount' => dec($l['discount']), 'tax_rate' => $l['tax_rate'], 'line_total' => dec($l['total'])];
$cx = ['paid' => 0, 'balance' => $t['total'], 'prev_balance' => 0, 'parent' => null, 'prepared_by' => $admin['full_name'], 'authorized_by' => '', 'inv_total' => 0, 'inv_prior' => 0];
echo '<div class="flash noprint">Preview only. Nothing has been saved. Close this tab to go back to the form.</div>' . render_doc_html($d, $cx);
dm_foot();
