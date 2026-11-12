<?php
declare(strict_types=1);
/*
 * Public endpoint for quote.html?service=... (all quotation forms).
 * The answers are checked against /quote-forms.json, saved to MySQL (quotes, quote_files, quote_events)
 * and emailed to the team. As with the request forms, the two steps are independent and a JSON copy
 * is kept on disk, so a quotation is only lost if both the database and the email fail.
 */
require __DIR__ . '/lib/common.php';
require __DIR__ . '/lib/quotes.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); reply(405, ['ok' => false]); }
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) reply(413, ['ok' => false, 'error' => 'too_large']);

try { $services = quote_services(); } catch (Throwable $e) { error_log('audtix quote: ' . $e->getMessage()); reply(500, ['ok' => false, 'error' => 'server']); }
$service = (string) ($_POST['service'] ?? '');
if (!isset($services[$service])) reply(422, ['ok' => false, 'errors' => ['form' => 'Unknown service.']]);

$raw = (string) ($_POST['data'] ?? '');
if ($raw === '' || strlen($raw) > 200000) reply(422, ['ok' => false, 'errors' => ['form' => 'Missing or oversized form data.']]);
$in = json_decode($raw, true);
if (!is_array($in)) reply(422, ['ok' => false, 'errors' => ['form' => 'Invalid form data.']]);

// Bots: honeypot filled or submitted instantly. Give a normal-looking answer and drop it.
if (trim((string) ($in['website'] ?? '')) !== '' || (int) ($in['elapsed_ms'] ?? 99999) < 2500) {
    reply(200, ['ok' => true, 'quote_no' => 'QT-' . quote_service_tag($service) . '-' . gmdate('Y') . '-' . str_pad((string) random_int(1, 999), 5, '0', STR_PAD_LEFT), 'service' => $service]);
}

[$data, $rows, $err] = quote_validate($service, $in);
if (empty($in['consent'])) $err['consent'] = 'Please confirm so we can contact you.';
[$files, $fileErr] = quote_take_uploads('attachments');
if ($fileErr) $err['attachments'] = $fileErr;
if ($err) reply(422, ['ok' => false, 'errors' => $err]);

$ip = client_ip(); $ua = user_agent(); $year = (int) gmdate('Y');
$pdo = null; $dbOk = false; $dbErr = '';
try {
    $pdo = db();
    $c = $pdo->prepare('SELECT COUNT(*) FROM submission_log WHERE ip = ? AND created_at > ?');
    $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int) $c->fetchColumn() >= 10) reply(429, ['ok' => false, 'error' => 'rate_limited']);
    $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, 'quote', now()]);
} catch (PDOException $e) { $pdo = null; $dbErr = $e->getMessage(); }

$quoteNo = '';
if ($pdo) { try { $quoteNo = next_quote_no($pdo, $service, $year); } catch (Throwable $e) { $pdo = null; $dbErr = $e->getMessage(); } }
if ($quoteNo === '') $quoteNo = 'QT-' . quote_service_tag($service) . '-' . $year . '-E' . strtoupper(substr(base_convert((string) time(), 10, 36), -4) . bin2hex(random_bytes(1)));

$name = (string) $data['full_name']; $company = (string) ($data['company'] ?? '');
$client = $company !== '' ? $company : $name;
$summary = quote_summary($service, $rows);
$title = quote_service_title($service);
array_unshift($rows, ['Service', $title, 'Quotation']);

// Disk copy first: the third safety net.
$dir = storage_dir() . '/quotes/' . $quoteNo;
if (@mkdir($dir, 0750, true) || is_dir($dir)) {
    @file_put_contents("$dir/quote.json", json_encode(['quote_no' => $quoteNo, 'service' => $service, 'received_utc' => now(), 'ip' => $ip, 'data' => $data, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/* 1) database */
$pk = 0; $stored = [];
if ($pdo) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO quotes (quote_no, service, status, source, client_type, client_name, company, email, phone, location, summary, budget, needed_by, data_json, rows_json, files_count, email_status, ip, user_agent, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$quoteNo, $service, 'pending', 'website', $data['client_type'] ?: null, $name, $company ?: null, $data['email'], $data['phone'], ($data['location'] ?? '') ?: null,
                $summary ?: null, ($data['budget'] ?? '') ?: null, ($data['needed_by'] ?? '') ?: null,
                json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($rows, JSON_UNESCAPED_UNICODE), 0, 'pending', $ip, $ua, now(), now()]);
        $pk = (int) $pdo->lastInsertId();
        quote_event($pdo, $pk, null, 'system', 'Quotation request received from the website');
        $pdo->commit(); $dbOk = true;
        $stored = quote_store_files($pdo, $pk, $quoteNo, $files, 'client');
        notify_admins($pdo, 'quote', 'quotes', 'New quotation request ' . $quoteNo, $client . ' · ' . $title . ($summary ? ' · ' . $summary : ''), 'quote.php?id=' . $pk);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $dbErr = $e->getMessage(); $dbOk = false; }
}
if (!$dbOk && $files && is_dir($dir)) {  // keep the files on disk even without the database
    foreach ($files as $f) { $sn = bin2hex(random_bytes(12)) . '.' . $f['ext']; if (is_uploaded_file($f['tmp']) ? @move_uploaded_file($f['tmp'], "$dir/$sn") : @copy($f['tmp'], "$dir/$sn")) $stored[] = $f + ['path' => "$dir/$sn"]; }
}

/* 2) email the team */
$dbLine = $dbOk ? 'Saved to the database: yes' : 'Saved to the database: NO (database problem, this email is the record)';
$subject = '[QUOTE ' . quote_service_tag($service) . "] $quoteNo: $client";
$text = "New quotation request: $title\nQuote number: $quoteNo\nReceived (UTC): " . now() . "\n$dbLine\nDocuments: " . count($stored) . "\n" . quote_rows_text($rows);
$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#10202f"><h2 style="margin:0 0 6px">New quotation request: ' . h($title) . '</h2>'
      . '<p style="margin:0 0 12px"><b style="font-family:monospace;font-size:16px;background:#eaf2fc;padding:3px 8px;border-radius:5px">' . h($quoteNo) . '</b></p>'
      . '<p style="margin:0 0 12px;color:#5a6b7d">Received (UTC): ' . h(now()) . ' &middot; ' . h($dbLine) . ' &middot; Documents attached: ' . count($stored) . '</p>'
      . quote_rows_html($rows) . '</div>';
$att = []; $sum = 0;
foreach ($stored as $f) { if ($sum + $f['size'] > 14 * 1048576) continue; $sum += $f['size']; $att[] = ['name' => $f['orig'], 'mime' => $f['mime'], 'path' => $f['path']]; }
$skipMail = $dbOk && !notify_enabled('quote');
[$mailOk, $mailErr] = $skipMail ? [false, 'Email notifications for quotations are turned off'] : send_mail($subject, $text, $html, $att, (string) $data['email']);
if ($pdo && $dbOk) {
    try { $pdo->prepare('UPDATE quotes SET email_status = ?, email_error = ?, updated_at = ? WHERE id = ?')->execute([$mailOk ? 'sent' : ($skipMail ? 'pending' : 'failed'), $mailOk ? null : mb_substr($mailErr, 0, 500), now(), $pk]); } catch (Throwable $e) {}
}
if (!$dbOk && is_dir($dir)) @file_put_contents("$dir/status.txt", "db=failed ($dbErr)\nemail=" . ($mailOk ? 'sent' : "failed ($mailErr)") . "\n");

if (!$dbOk && !$mailOk) { error_log("audtix quote failed $quoteNo db=$dbErr mail=$mailErr"); reply(500, ['ok' => false, 'error' => 'server']); }
$out = ['ok' => true, 'quote_no' => $quoteNo, 'service' => $service, 'title' => $title];
if (cfg('debug')) { $out['db'] = $dbOk; $out['mail'] = $mailOk; }
reply(200, $out);
