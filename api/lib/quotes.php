<?php
declare(strict_types=1);
/* Quotation helpers shared by api/quote.php and the admin panel.
   The form definition lives in /quote-forms.json; the same file drives quote.html. */

const QUOTE_STATUSES = [
    'pending'   => 'Pending',
    'in_review' => 'In review',
    'responded' => 'Responded',
    'approved'  => 'Approved',
    'rejected'  => 'Rejected',
    'cancelled' => 'Cancelled',
    'completed' => 'Completed',
    'spam'      => 'Spam',
];
const QUOTE_STATUS_COLORS = [
    'pending' => 'warning', 'in_review' => 'info', 'responded' => 'primary', 'approved' => 'success',
    'rejected' => 'danger', 'cancelled' => 'secondary', 'completed' => 'success', 'spam' => 'dark',
];
const QUOTE_STATUS_ICONS = [
    'pending' => 'hourglass-split', 'in_review' => 'search', 'responded' => 'send-check', 'approved' => 'hand-thumbs-up',
    'rejected' => 'hand-thumbs-down', 'cancelled' => 'x-octagon', 'completed' => 'check2-all', 'spam' => 'trash3',
];
const QUOTE_MAX_FILE = 5 * 1024 * 1024, QUOTE_MAX_TOTAL = 15 * 1024 * 1024, QUOTE_MAX_FILES = 5;

function quote_spec(): array {
    static $spec = null;
    if ($spec === null) {
        $spec = json_decode((string) @file_get_contents(dirname(__DIR__, 2) . '/quote-forms.json'), true);
        if (!is_array($spec) || empty($spec['services'])) throw new RuntimeException('quote-forms.json is missing or invalid');
    }
    return $spec;
}
function quote_services(): array { return quote_spec()['services']; }
function quote_service_title(string $k): string { return quote_services()[$k]['title'] ?? strtoupper($k); }
function quote_service_tag(string $k): string { return quote_services()[$k]['tag'] ?? strtoupper($k); }

/** Every section shown for a service: contact, the service's own sections, then budget/timing. */
function quote_sections(string $service): array {
    $sp = quote_spec(); $svc = $sp['services'][$service] ?? null;
    if (!$svc) return [];
    return array_merge([$sp['contact']], $svc['sections'], [$sp['closing']]);
}

/** Same rule language as quote.js: name=value, name!=value, name~value (checkbox ticked). */
function quote_rule_ok(?string $rule, array $data): bool {
    if (!$rule) return true;
    if (preg_match('/^(\w+)~(.+)$/', $rule, $m)) return is_array($data[$m[1]] ?? null) && in_array($m[2], $data[$m[1]], true);
    if (preg_match('/^(\w+)!=(.*)$/', $rule, $m)) { $v = is_string($data[$m[1]] ?? null) ? $data[$m[1]] : ''; return $v !== '' && $v !== $m[2]; }
    if (preg_match('/^(\w+)=(.+)$/', $rule, $m)) return ($data[$m[1]] ?? '') === $m[2];
    return true;
}

function quote_opt_label(array $f, string $v): string {
    foreach ($f['options'] ?? [] as $o) if ((string) $o[0] === $v) return (string) $o[1];
    return $v;
}

/**
 * Validates submitted answers against the definition.
 * Returns [clean data, labelled rows [[label, value, section]], errors [field => message]].
 * Anything not in the definition is dropped; hidden fields are dropped.
 */
function quote_validate(string $service, array $in, bool $strict = true): array {
    $clean = []; $rows = []; $err = [];
    foreach (quote_sections($service) as $sec) {
        foreach ($sec['fields'] as $f) {
            $n = $f['name']; $type = $f['type']; $req = !empty($f['required']) && ($strict || $n === 'full_name');  // admins entering a phone enquiry only need a name
            if (!quote_rule_ok($f['show'] ?? null, $clean + $in)) continue;
            $raw = $in[$n] ?? null; $val = null; $shown = '';
            switch ($type) {
                case 'checkboxes':
                    $allowed = array_map(fn($o) => (string) $o[0], $f['options']);
                    $val = array_values(array_unique(array_filter(is_array($raw) ? array_map('strval', $raw) : [], fn($x) => in_array($x, $allowed, true))));
                    if ($req && !$val) $err[$n] = 'Choose at least one.';
                    $shown = implode(', ', array_map(fn($x) => quote_opt_label($f, $x), $val));
                    break;
                case 'select': case 'radio':
                    $v = is_string($raw) ? trim($raw) : '';
                    $allowed = array_map(fn($o) => (string) $o[0], $f['options']);
                    if ($v !== '' && !in_array($v, $allowed, true)) { $err[$n] = 'Choose one of the options.'; $v = ''; }
                    if ($req && $v === '') $err[$n] = $err[$n] ?? 'Please choose an answer.';
                    $val = $v; $shown = $v === '' ? '' : quote_opt_label($f, $v);
                    break;
                case 'number':
                    $v = is_scalar($raw) ? trim((string) $raw) : '';
                    if ($v === '') { if ($req) $err[$n] = 'This is required.'; $val = ''; break; }
                    if (!preg_match('/^\d{1,9}$/', str_replace([',', ' '], '', $v))) { $err[$n] = 'Enter a whole number.'; break; }
                    $num = (int) str_replace([',', ' '], '', $v);
                    if (isset($f['min']) && $num < $f['min']) { $err[$n] = 'The smallest allowed is ' . $f['min'] . '.'; break; }
                    if (isset($f['max']) && $num > $f['max']) { $err[$n] = 'The largest allowed is ' . number_format($f['max']) . '.'; break; }
                    $val = (string) $num; $shown = number_format($num);
                    break;
                case 'date':
                    $v = is_string($raw) ? trim($raw) : '';
                    if ($v === '') { if ($req) $err[$n] = 'Pick a date.'; $val = ''; break; }
                    $d = DateTime::createFromFormat('!Y-m-d', $v);
                    if (!$d || $d->format('Y-m-d') !== $v) { $err[$n] = 'Enter a valid date.'; break; }
                    if (!empty($f['future']) && $v < gmdate('Y-m-d', time() - 86400)) { $err[$n] = 'Pick today or a later date.'; break; }
                    $val = $v; $shown = $d->format('j M Y');
                    break;
                case 'items':
                    $list = is_array($raw) ? array_slice($raw, 0, (int) ($f['max_rows'] ?? 40)) : [];
                    $val = []; $lines = [];
                    foreach ($list as $i => $r) {
                        if (!is_array($r)) continue;
                        $row = []; $empty = true;
                        foreach ($f['columns'] as $c) { $cv = is_scalar($r[$c['name']] ?? null) ? mb_substr(trim((string) $r[$c['name']]), 0, (int) ($c['max'] ?? 300)) : ''; $row[$c['name']] = $cv; if ($cv !== '') $empty = false; }
                        if ($empty) continue;
                        foreach ($f['columns'] as $c) {
                            $cv = $row[$c['name']];
                            if (!empty($c['required']) && $cv === '') { $err[$n] = 'Line ' . (count($val) + 1) . ': fill in ' . strtolower($c['label']) . '.'; }
                            if ($c['type'] === 'number' && $cv !== '') {
                                if (!preg_match('/^\d{1,9}$/', $cv) || (isset($c['min']) && (int) $cv < $c['min']) || (isset($c['max']) && (int) $cv > $c['max'])) $err[$n] = 'Line ' . (count($val) + 1) . ': ' . strtolower($c['label']) . ' must be a whole number from ' . ($c['min'] ?? 0) . '.';
                            }
                        }
                        $val[] = $row;
                        $lines[] = ($row['qty'] ?? '') !== '' ? $row['qty'] . ' x ' . implode(' - ', array_filter([$row['item'] ?? '', $row['spec'] ?? ''])) : implode(' - ', array_filter($row));
                    }
                    if ($req && !$val && !isset($err[$n])) $err[$n] = 'Add at least one item.';
                    $shown = implode("\n", $lines);
                    break;
                default: // text, email, tel, textarea
                    $v = is_scalar($raw) ? trim((string) $raw) : '';
                    $v = mb_substr($v, 0, (int) ($f['max'] ?? ($type === 'textarea' ? 3000 : 300)));
                    if ($req && mb_strlen($v) < ($type === 'textarea' ? 3 : 2)) $err[$n] = 'This is required.';
                    elseif ($v !== '' && $type === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) $err[$n] = 'Enter a valid email address.';
                    elseif ($v !== '' && $type === 'tel') { $d = preg_replace('/\D/', '', $v); if (!preg_match('/^[+\d\s().-]+$/', $v) || strlen($d) < 7 || strlen($d) > 15) $err[$n] = 'Enter a valid phone number.'; }
                    $val = $v; $shown = $v;
            }
            if (!isset($err[$n])) { $clean[$n] = $val; if ($shown !== '') $rows[] = [$f['label'], $shown, $sec['title']]; }
        }
    }
    return [$clean, $rows, $err];
}

/** Short line for lists: the service's first ticked/choice field, or the first item lines. */
function quote_summary(string $service, array $rows): string {
    $own = array_column(quote_services()[$service]['sections'] ?? [], 'title');
    foreach ($rows as $r) if (in_array($r[2], $own, true)) return mb_strimwidth(str_replace("\n", '; ', $r[1]), 0, 480, '…');
    return '';
}

function next_quote_no(PDO $pdo, string $service, int $year): string {
    $n = next_request_number($pdo, 'qt_' . $service, $year);
    return 'QT-' . quote_service_tag($service) . '-' . $year . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
}

function quote_event(PDO $pdo, int $quotePk, ?int $adminId, string $type, string $note): void {
    $pdo->prepare('INSERT INTO quote_events (quote_pk, admin_id, event_type, note, created_at) VALUES (?,?,?,?,?)')
        ->execute([$quotePk, $adminId, $type, mb_substr($note, 0, 2000), now()]);
}

/** Checks uploaded files by signature. Returns [list, error]. */
function quote_take_uploads(string $field, int $max = QUOTE_MAX_FILES, bool $pdfOnly = false): array {
    $out = []; $total = 0;
    if (empty($_FILES[$field])) return [[], ''];
    $f = $_FILES[$field];
    $names = (array) $f['name']; $tmps = (array) $f['tmp_name']; $errs = (array) $f['error']; $sizes = (array) $f['size'];
    foreach ($names as $i => $orig) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) return [[], 'A file could not be uploaded. Try again with smaller files.'];
        if (count($out) >= $max) return [[], 'Upload at most ' . $max . ' files.'];
        $size = (int) $sizes[$i]; $tmp = (string) $tmps[$i]; $total += $size;
        if ($size > QUOTE_MAX_FILE) return [[], $orig . ' is larger than 5 MB.'];
        if (!is_uploaded_file($tmp) && !(cfg('allow_test_uploads') && is_file($tmp))) continue;
        $head = (string) file_get_contents($tmp, false, null, 0, 8);
        if (strncmp($head, '%PDF', 4) === 0) { $mime = 'application/pdf'; $ext = 'pdf'; }
        elseif (!$pdfOnly && strncmp($head, "\xFF\xD8\xFF", 3) === 0) { $mime = 'image/jpeg'; $ext = 'jpg'; }
        elseif (!$pdfOnly && strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) { $mime = 'image/png'; $ext = 'png'; }
        else return [[], $orig . ($pdfOnly ? ' must be a PDF.' : ' must be a PDF, JPG or PNG.')];
        $out[] = ['tmp' => $tmp, 'orig' => mb_substr(preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]/', '_', basename((string) $orig)), 0, 200), 'mime' => $mime, 'ext' => $ext, 'size' => $size];
    }
    if ($total > QUOTE_MAX_TOTAL) return [[], 'Together the files must be under 15 MB.'];
    return [$out, ''];
}

/** Moves checked uploads into storage/quotes/<quote_no>/ and records them. Returns stored list with paths. */
function quote_store_files(PDO $pdo, int $quotePk, string $quoteNo, array $files, string $kind): array {
    $dir = storage_dir() . '/quotes/' . $quoteNo; $stored = [];
    if (!$files || !(@mkdir($dir, 0750, true) || is_dir($dir))) return [];
    $ins = $pdo->prepare('INSERT INTO quote_files (quote_pk, kind, original_name, stored_name, mime, size_bytes, sha256, created_at) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($files as $f) {
        $sn = bin2hex(random_bytes(12)) . '.' . $f['ext'];
        $ok = is_uploaded_file($f['tmp']) ? @move_uploaded_file($f['tmp'], "$dir/$sn") : @copy($f['tmp'], "$dir/$sn");
        if (!$ok) continue;
        $sha = hash_file('sha256', "$dir/$sn");
        $ins->execute([$quotePk, $kind, $f['orig'], $sn, $f['mime'], $f['size'], $sha, now()]);
        $stored[] = $f + ['stored' => $sn, 'path' => "$dir/$sn", 'id' => (int) $pdo->lastInsertId()];
    }
    if ($kind === 'client') $pdo->prepare('UPDATE quotes SET files_count = (SELECT COUNT(*) FROM quote_files WHERE quote_pk = ? AND kind = ?) WHERE id = ?')->execute([$quotePk, 'client', $quotePk]);
    return $stored;
}

function quote_rows_html(array $rows): string {
    $tr = ''; $last = '';
    foreach ($rows as $r) {
        if (($r[2] ?? '') !== $last) { $last = $r[2]; $tr .= '<tr><td colspan="2" style="padding:14px 10px 4px;color:#1664c0;font-size:12px;font-weight:bold;text-transform:uppercase;letter-spacing:.05em">' . h($last) . '</td></tr>'; }
        $tr .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #e4ebf3;color:#5a6b7d;vertical-align:top;width:34%">' . h($r[0]) . '</td><td style="padding:6px 10px;border-bottom:1px solid #e4ebf3;white-space:pre-wrap">' . h($r[1]) . '</td></tr>';
    }
    return '<table style="border-collapse:collapse;width:100%;max-width:760px">' . $tr . '</table>';
}
function quote_rows_text(array $rows): string {
    $t = ''; $last = '';
    foreach ($rows as $r) { if (($r[2] ?? '') !== $last) { $last = $r[2]; $t .= "\n== $last ==\n"; } $t .= $r[0] . ': ' . $r[1] . "\n"; }
    return $t;
}
function money(?string $amount, ?string $cur): string {
    if ($amount === null || $amount === '') return '';
    $a = (float) $amount;
    return ($cur ?: 'UGX') . ' ' . number_format($a, fmod($a, 1.0) == 0.0 ? 0 : 2);
}

/** Permanently deletes a quotation, its history and its stored files (admin only). */
function quote_delete(PDO $pdo, array $q, int $adminId): void {
    $dir = storage_dir() . '/quotes/' . $q['quote_no'];
    if (preg_match('/^QT-[A-Z]+-\d{4}-[A-Z0-9]+$/', (string) $q['quote_no']) && is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
    $pdo->prepare('DELETE FROM quote_files WHERE quote_pk = ?')->execute([$q['id']]);
    $pdo->prepare('DELETE FROM quote_events WHERE quote_pk = ?')->execute([$q['id']]);
    $pdo->prepare('DELETE FROM quotes WHERE id = ?')->execute([$q['id']]);
    log_activity($adminId, 'quote_deleted', $q['quote_no']);
}
