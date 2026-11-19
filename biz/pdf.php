<?php
require __DIR__ . '/inc/boot.php';
$u = biz_require(); $a = account(); $pdo = db(); $d = biz_doc($pdo, (int) $a['id'], (int) ($_GET['id'] ?? 0));
if (!$d) { http_response_code(404); exit('Document not found.'); }
$pdf = render_doc_pdf($d, biz_context($pdo, $d));
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', $d['doc_no'] . '-' . $d['customer_name']) . '.pdf';
header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($pdf)); header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $name . '"'); echo $pdf;
