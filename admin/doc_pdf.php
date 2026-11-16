<?php
require __DIR__ . '/inc/boot.php';
require_once __DIR__ . '/inc/docs.php';
require_once __DIR__ . '/inc/pdf.php';
require_once __DIR__ . '/inc/render.php';
$admin = doc_need('doc.print'); $pdo = db();
$d = get_document($pdo, (int) ($_GET['id'] ?? 0));
if (!$d) { http_response_code(404); exit('Document not found.'); }
$pdf = render_doc_pdf($d, doc_context($pdo, $d));
doc_event($pdo, (int) $d['id'], (int) $admin['id'], 'downloaded', 'PDF ' . (isset($_GET['inline']) ? 'viewed' : 'downloaded'));
$name = preg_replace('/[^A-Za-z0-9._-]/', '_', $d['doc_no'] . '-' . $d['customer_name']) . '.pdf';
header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($pdf)); header('Content-Disposition: ' . (isset($_GET['inline']) ? 'inline' : 'attachment') . '; filename="' . $name . '"'); header('X-Content-Type-Options: nosniff');
echo $pdf;
