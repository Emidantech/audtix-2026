<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/rfq.php';
$admin = require_login();
$kind = ($_GET['kind'] ?? '') === 'request' ? 'request' : 'quote';
if (!rfq_can_view($kind)) require_perm($kind === 'request' ? 'requests.view' : 'quotes.view');
$pdo = db(); $r = rfq_load($pdo, $kind, (int) ($_GET['id'] ?? 0));
if (!$r) { http_response_code(404); exit('Request not found.'); }
$pdf = render_rfq_pdf($r);
log_activity((int) $admin['id'], 'rfq_pdf', $r['ref']);
$name = 'RFQ-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $r['ref'] . '-' . $r['name']) . '.pdf';
header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($pdf)); header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff');
echo $pdf;
