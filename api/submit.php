<?php
declare(strict_types=1);
/*
 * Public endpoint for the Tax and Audit request forms.
 * Every request is (1) saved to MySQL and (2) emailed, and the two steps are independent:
 * if one fails the other still happens. A copy of the data is also kept on disk as a third safety net.
 */
require __DIR__ . '/lib/common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); reply(405, ['ok' => false]); }
if (empty($_POST) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) reply(413, ['ok' => false, 'error' => 'too_large']);

const MAX_FILE = 5 * 1024 * 1024, MAX_TOTAL = 15 * 1024 * 1024, MAX_PER = 3;

$category = (string) ($_POST['category'] ?? '');
if (!isset(CATEGORIES[$category])) reply(422, ['ok' => false, 'errors' => ['form' => 'Unknown form.']]);

$raw = (string) ($_POST['data'] ?? '');
if ($raw === '' || strlen($raw) > 200000) reply(422, ['ok' => false, 'errors' => ['form' => 'Missing or oversized form data.']]);
$data = json_decode($raw, true);
if (!is_array($data)) reply(422, ['ok' => false, 'errors' => ['form' => 'Invalid form data.']]);

$rowsIn = json_decode((string) ($_POST['rows'] ?? '[]'), true);
$rows = [];
if (is_array($rowsIn)) {
    foreach (array_slice($rowsIn, 0, 400) as $r) {
        if (!is_array($r) || count($r) < 2) continue;
        $label = mb_substr(trim((string) $r[0]), 0, 200); $value = mb_substr(trim((string) (is_array($r[1]) ? implode(', ', $r[1]) : $r[1])), 0, 4000);
        if ($label === '' ) continue;
        $rows[] = [$label, $value, isset($r[2]) ? mb_substr((string) $r[2], 0, 120) : ''];
    }
}
$fileLabels = json_decode((string) ($_POST['file_labels'] ?? '{}'), true);
if (!is_array($fileLabels)) $fileLabels = [];

/* ---------- bots: honeypot and instant submits get a normal-looking reply and are dropped ---------- */
if (trim((string) ($data['website'] ?? '')) !== '' || (int) ($data['elapsed_ms'] ?? 99999) < 2500) {
    reply(200, ['ok' => true, 'request_id' => fallback_request_id($category), 'category' => $category]);
}

/* ---------- validation ---------- */
function s($v, int $max = 500): string { return is_string($v) ? mb_substr(trim($v), 0, $max) : ''; }
$err = [];
$nameKey = $category === 'tax' ? 'display_name' : 'org_name';
$svcKey  = $category === 'tax' ? 'services' : 'audit_types';
$perKey  = $category === 'tax' ? 'tax_periods' : 'audit_period';
if (mb_strlen(s($data[$nameKey] ?? '')) < 2) $err[$category === 'tax' ? 'display_name' : 'org_name'] = 'Name is required.';
$email = s($data['email'] ?? '', 190);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err['email'] = 'Enter a valid email address.';
$phone = s($data['phone'] ?? '', 60); $digits = preg_replace('/\D/', '', $phone);
if ($phone === '' || !preg_match('/^[+\d\s().-]+$/', $phone) || strlen($digits) < 7 || strlen($digits) > 15) $err['phone'] = 'Enter a valid phone number.';
if (!is_array($data[$svcKey] ?? null) || !count($data[$svcKey])) $err[$svcKey] = 'Choose at least one service.';
if (s($data[$perKey] ?? '') === '') $err[$perKey] = 'This is required.';
if (empty($data['consent'])) $err['consent'] = 'Please confirm so we can contact you.';
if (empty($data['accuracy_confirmed'])) $err['accuracy_confirmed'] = 'Please confirm that the information is true and complete.';
$tin = preg_replace('/\s+/', '', s($data['tin'] ?? '', 20));
if ($tin !== '' && !preg_match('/^[0-9]{10}$/', $tin)) $err['tin'] = 'A TIN has 10 digits.';

/* ---------- files ---------- */
$files = []; $total = 0; $per = [];
foreach ($_FILES as $key => $f) {
    if (!preg_match('/^file__[a-z0-9_]{1,50}$/', (string) $key)) continue;
    $names = (array) $f['name']; $tmps = (array) $f['tmp_name']; $errs = (array) $f['error']; $sizes = (array) $f['size'];
    foreach ($names as $i => $orig) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) { $err['files'] = 'A file could not be uploaded. Try again with smaller files.'; continue; }
        $size = (int) $sizes[$i]; $tmp = (string) $tmps[$i];
        $per[$key] = ($per[$key] ?? 0) + 1; $total += $size;
        if ($per[$key] > MAX_PER) { $err['files'] = 'Upload at most ' . MAX_PER . ' files for each document type.'; continue; }
        if ($size > MAX_FILE) { $err['files'] = $orig . ' is larger than 5 MB.'; continue; }
        if (!is_uploaded_file($tmp) && !(cfg('allow_test_uploads') && is_file($tmp))) continue;
        $head = (string) file_get_contents($tmp, false, null, 0, 8);
        if (strncmp($head, '%PDF', 4) === 0) { $mime = 'application/pdf'; $ext = 'pdf'; }
        elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0) { $mime = 'image/jpeg'; $ext = 'jpg'; }
        elseif (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) { $mime = 'image/png'; $ext = 'png'; }
        else { $err['files'] = $orig . ' must be a PDF, JPG or PNG.'; continue; }
        $files[] = ['key' => $key, 'tmp' => $tmp, 'orig' => mb_substr(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]/', '_', basename((string) $orig)), 0, 200), 'mime' => $mime, 'ext' => $ext, 'size' => $size];
    }
}
if ($total > MAX_TOTAL) $err['files'] = 'Together the files must be under 15 MB.';
if ($err) reply(422, ['ok' => false, 'errors' => $err]);

/* ---------- rate limit (skipped if the database is unreachable) ---------- */
$ip = client_ip(); $ua = user_agent(); $year = (int) gmdate('Y');
$pdo = null; $dbOk = false; $dbErr = '';
try {
    $pdo = db();
    $c = $pdo->prepare('SELECT COUNT(*) FROM submission_log WHERE ip = ? AND created_at > ?');
    $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
    if ((int) $c->fetchColumn() >= 10) reply(429, ['ok' => false, 'error' => 'rate_limited']);
    $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, $category, now()]);
} catch (PDOException $e) { $pdo = null; $dbErr = $e->getMessage(); }

/* ---------- allocate the request id ---------- */
$requestId = ''; $seq = 0;
if ($pdo) {
    try { $seq = next_request_number($pdo, $category, $year); $requestId = format_request_id($category, $year, $seq); }
    catch (Throwable $e) { $pdo = null; $dbErr = $e->getMessage(); }
}
if ($requestId === '') $requestId = fallback_request_id($category);

/* ---------- keep the files and a JSON backup on disk (also the third safety net) ---------- */
$dir = storage_dir() . '/requests/' . $requestId;
$stored = [];
if (@mkdir($dir, 0750, true) || is_dir($dir)) {
    foreach ($files as $f) {
        $sn = bin2hex(random_bytes(12)) . '.' . $f['ext'];
        $ok = is_uploaded_file($f['tmp']) ? @move_uploaded_file($f['tmp'], "$dir/$sn") : @copy($f['tmp'], "$dir/$sn");
        if ($ok) $stored[] = $f + ['stored' => $sn, 'path' => "$dir/$sn", 'sha' => hash_file('sha256', "$dir/$sn")];
    }
    @file_put_contents("$dir/request.json", json_encode(['request_id' => $requestId, 'category' => $category, 'received_utc' => now(), 'ip' => $ip, 'data' => $data, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/* ---------- 1) save to the database ---------- */
$pk = 0; $clientName = s($data[$nameKey]);
$svcRow = ''; foreach ($rows as $r) { if (in_array($r[0], ['Services', 'Audit services needed'], true)) { $svcRow = mb_substr($r[1], 0, 500); break; } }
if ($pdo) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO requests (request_id, category, status, client_name, contact_person, email, phone, service_summary, data_json, rows_json, files_count, email_status, assigned_to, ip, user_agent, created_at, updated_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$requestId, $category, 'new', $clientName, s($data['contact_person'] ?? '', 190) ?: null, $email, $phone, $svcRow ?: null,
                json_encode($data, JSON_UNESCAPED_UNICODE), json_encode($rows, JSON_UNESCAPED_UNICODE), count($stored), 'pending', null, $ip, $ua, now(), now()]);
        $pk = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO request_files (request_pk, field_key, doc_label, original_name, stored_name, mime, size_bytes, sha256, created_at) VALUES (?,?,?,?,?,?,?,?,?)');
        foreach ($stored as $f) $ins->execute([$pk, $f['key'], mb_substr((string) ($fileLabels[$f['key']] ?? ''), 0, 160) ?: null, $f['orig'], $f['stored'], $f['mime'], $f['size'], $f['sha'], now()]);
        $pdo->commit(); $dbOk = true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); $dbErr = $e->getMessage(); $dbOk = false; }
}
if ($dbOk) notify_admins($pdo, $category . '_request', 'requests', 'New ' . ($category === 'tax' ? 'tax' : 'audit') . ' request ' . $requestId, $clientName . ($svcRow ? ' · ' . $svcRow : ''), 'request.php?id=' . $pk);

/* ---------- 2) email a copy (independent of the database) ---------- */
$tag = strtoupper($category);
$tr = ''; $tx = '';
foreach ($rows as $r) {
    if ($r[1] === '') continue;
    $tr .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e4ebf3;color:#5a6b7d;vertical-align:top;width:34%">' . h($r[0]) . '</td><td style="padding:6px 10px;border-bottom:1px solid #e4ebf3;white-space:pre-wrap">' . h($r[1]) . '</td></tr>';
    $tx .= $r[0] . ': ' . $r[1] . "\n";
}
$dbLine = $dbOk ? 'Saved to the database: yes' : 'Saved to the database: NO (database problem, this email is the record)';
$subject = "[$tag] New request $requestId: $clientName";
$text = "New " . ($category === 'tax' ? 'tax' : 'audit') . " service request\nRequest ID: $requestId\nCategory: $tag\nReceived (UTC): " . now() . "\n$dbLine\nDocuments: " . count($stored) . "\n\n$tx";
$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#10202f"><h2 style="margin:0 0 6px">New ' . ($category === 'tax' ? 'tax' : 'audit') . ' service request</h2>'
      . '<p style="margin:0 0 12px"><b style="font-family:monospace;font-size:16px;background:#eaf2fc;padding:3px 8px;border-radius:5px">' . h($requestId) . '</b> &nbsp; <span style="background:#10202f;color:#fff;padding:3px 8px;border-radius:5px;font-size:12px">' . h($tag) . '</span></p>'
      . '<p style="margin:0 0 12px;color:#5a6b7d">Received (UTC): ' . h(now()) . ' &middot; ' . h($dbLine) . ' &middot; Documents attached: ' . count($stored) . '</p>'
      . '<table style="border-collapse:collapse;width:100%;max-width:760px">' . $tr . '</table></div>';
$att = []; $sum = 0;
foreach ($stored as $f) { if ($sum + $f['size'] > 14 * 1048576) continue; $sum += $f['size']; $att[] = ['name' => $f['key'] === '' ? $f['orig'] : substr($f['key'], 6) . '_' . $f['orig'], 'mime' => $f['mime'], 'path' => $f['path']]; }
// The team email can be turned off in Admin > Business settings, but only when the database copy exists.
$skipMail = $dbOk && !notify_enabled($category);
[$mailOk, $mailErr] = $skipMail ? [false, 'Email notifications for this form are turned off'] : send_mail($subject, $text, $html, $att, $email);
if ($pdo && $dbOk) {
    try { $pdo->prepare('UPDATE requests SET email_status = ?, email_error = ?, updated_at = ? WHERE id = ?')->execute([$mailOk ? 'sent' : ($skipMail ? 'pending' : 'failed'), $mailOk ? null : mb_substr($mailErr, 0, 500), now(), $pk]); } catch (Throwable $e) {}
}
if (!$dbOk && @is_dir($dir)) @file_put_contents("$dir/status.txt", "db=failed ($dbErr)\nemail=" . ($mailOk ? 'sent' : "failed ($mailErr)") . "\n");

/* ---------- answer: success if either copy exists ---------- */
if (!$dbOk && !$mailOk) { error_log("audtix submit failed $requestId db=$dbErr mail=$mailErr"); reply(500, ['ok' => false, 'error' => 'server']); }
$out = ['ok' => true, 'request_id' => $requestId, 'category' => $category];
if (cfg('debug')) { $out['db'] = $dbOk; $out['mail'] = $mailOk; }
reply(200, $out);
