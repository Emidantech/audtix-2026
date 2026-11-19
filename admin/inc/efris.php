<?php
/* EFRIS (URA e-invoicing) integration layer.
   What is real here: building an EFRIS-style invoice payload from a posted invoice, keeping a log with status/FDN/QR, and sending the payload
   to a gateway you configure (a URA-accredited service provider or your own middleware) with a bearer token.
   What needs URA: the taxpayer registration, device number, test/production access and (for direct use) the signing/encryption keys.
   Until those are in place, use "Manual" mode: download the payload and capture it in the EFRIS portal. */

function efris_cfg(): array {
    $k = ['efris_mode' => 'manual', 'efris_env' => 'test', 'efris_tin' => '', 'efris_legal_name' => '', 'efris_device_no' => '', 'efris_branch' => '', 'efris_gateway_url' => '', 'efris_gateway_token' => ''];
    foreach ($k as $key => $d) $k[$key] = doc_setting($key, $d);
    if ($k['efris_tin'] === '') $k['efris_tin'] = doc_setting('company_tin');
    if ($k['efris_legal_name'] === '') $k['efris_legal_name'] = doc_setting('company_name', 'Audtix Systems Ltd');
    return $k;
}
function efris_ready(array $c): bool { return $c['efris_mode'] === 'gateway' && $c['efris_gateway_url'] !== '' && $c['efris_tin'] !== ''; }

function efris_payload(PDO $pdo, int $docId): ?array {
    $s = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND doc_type = 'invoice'"); $s->execute([$docId]); $d = $s->fetch(); if (!$d) return null;
    $l = $pdo->prepare('SELECT * FROM document_lines WHERE document_id = ? ORDER BY line_no'); $l->execute([$docId]); $lines = $l->fetchAll(); $c = efris_cfg();
    $goods = []; foreach ($lines as $i => $x) $goods[] = ['item' => (string) $x['description'], 'itemCode' => 'L' . ($i + 1), 'qty' => (float) $x['quantity'], 'unitOfMeasure' => (string) ($x['unit'] ?: 'PCE'), 'unitPrice' => (float) $x['unit_price'],
        'total' => (float) $x['line_total'], 'discountTotal' => (float) $x['discount'], 'taxRate' => round((float) $x['tax_rate'] / 100, 4), 'tax' => (float) $x['tax_amount'], 'orderNumber' => $i];
    return ['sellerDetails' => ['tin' => $c['efris_tin'], 'legalName' => $c['efris_legal_name'], 'deviceNo' => $c['efris_device_no'], 'branch' => $c['efris_branch'], 'environment' => $c['efris_env']],
        'basicInformation' => ['invoiceNo' => $d['doc_no'], 'issuedDate' => $d['doc_date'], 'currency' => $d['currency'], 'invoiceType' => '1', 'invoiceKind' => '1', 'dueDate' => $d['due_date']],
        'buyerDetails' => ['buyerType' => $d['customer_tin'] ? '0' : '1', 'buyerTin' => (string) $d['customer_tin'], 'buyerLegalName' => $d['customer_name'], 'buyerAddress' => (string) $d['customer_address'], 'buyerMobilePhone' => (string) $d['customer_phone'], 'buyerEmail' => (string) $d['customer_email']],
        'goodsDetails' => $goods, 'summary' => ['netAmount' => (float) $d['total'] - (float) $d['tax_total'], 'taxAmount' => (float) $d['tax_total'], 'grossAmount' => (float) $d['total'], 'itemCount' => count($goods)]];
}

/** Sends the payload to the configured gateway. Returns [ok, response array, error text]. */
function efris_send(array $c, array $payload): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE); $hdr = ['Content-Type: application/json', 'Accept: application/json']; if ($c['efris_gateway_token'] !== '') $hdr[] = 'Authorization: Bearer ' . $c['efris_gateway_token'];
    if (!preg_match('#^https://#i', $c['efris_gateway_url'])) return [false, [], 'The gateway address must start with https://'];
    $raw = false; $code = 0;
    if (function_exists('curl_init')) { $ch = curl_init($c['efris_gateway_url']); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8]); $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); if ($raw === false) $err = curl_error($ch); curl_close($ch); }
    else { $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hdr), 'content' => $body, 'timeout' => 25, 'ignore_errors' => true]]); $raw = @file_get_contents($c['efris_gateway_url'], false, $ctx); $code = (int) (preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m) ? $m[1] : 0); }
    if ($raw === false) return [false, [], 'Could not reach the gateway' . (isset($err) && $err ? ': ' . $err : '.')];
    $j = json_decode((string) $raw, true); $j = is_array($j) ? $j : ['raw' => mb_substr((string) $raw, 0, 500)];
    $fdn = (string) ($j['fdn'] ?? $j['invoiceNo'] ?? ''); if ($code >= 200 && $code < 300 && $fdn !== '') return [true, $j, ''];
    return [false, $j, 'Gateway answered HTTP ' . $code . ': ' . mb_substr((string) ($j['error'] ?? $j['message'] ?? $j['raw'] ?? 'no fiscal document number returned'), 0, 300)];
}
