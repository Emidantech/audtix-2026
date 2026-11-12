<?php
declare(strict_types=1);
/* Shared helpers for the public API and the admin panel. PHP 8.0+, PDO. */

date_default_timezone_set('UTC');

function cfg(?string $key = null, $default = null) {
    static $c = null;
    if ($c === null) {
        $root = dirname(__DIR__, 2);                      // site root (public_html)
        $paths = [];
        if (getenv('AUDTIX_CONFIG')) $paths[] = getenv('AUDTIX_CONFIG');
        $paths[] = dirname($root) . '/audtix-config.php';  // preferred: outside the web root
        $paths[] = dirname(__DIR__) . '/config.php';       // fallback: api/config.php (blocked by .htaccess)
        $c = [];
        foreach ($paths as $p) { if (is_file($p)) { $c = (array) require $p; break; } }
    }
    return $key === null ? $c : ($c[$key] ?? $default);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = cfg('db_dsn');
    $user = cfg('db_user'); $pass = cfg('db_pass');
    if (!$dsn) $dsn = 'mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name', '') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    if (strpos($dsn, 'mysql') === 0) $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function now(): string { return gmdate('Y-m-d H:i:s'); }
function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string) $ip, 0, 45);
}
function user_agent(): string { return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }
function eat(?string $utc): string {  // show UTC database times in East Africa Time
    if (!$utc) return '';
    try { $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone('Africa/Kampala')); return $d->format('d M Y, H:i'); }
    catch (Throwable $e) { return $utc; }
}

/* ---------- storage ---------- */
function storage_dir(): string {
    $d = (string) cfg('storage_dir', '');
    if ($d === '') $d = dirname(__DIR__, 3) . '/audtix-storage';
    if (!is_dir($d) && !@mkdir($d, 0750, true)) {
        $d = dirname(__DIR__, 2) . '/private';              // inside the web root: protected by private/.htaccess
        if (!is_dir($d)) @mkdir($d, 0750, true);
        if (!is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    return $d;
}

/* ---------- request ids: TAX-2026-00001 / AUDIT-2026-00001 ---------- */
const CATEGORIES = ['tax' => 'TAX', 'audit' => 'AUDIT'];

function next_request_number(PDO $pdo, string $category, int $year): int {
    // Portable and race-safe: the UPDATE row-locks the counter inside the transaction (InnoDB).
    for ($try = 0; $try < 3; $try++) {
        $pdo->beginTransaction();
        try {
            $u = $pdo->prepare('UPDATE id_counters SET last_number = last_number + 1 WHERE category = ? AND yr = ?');
            $u->execute([$category, $year]);
            if ($u->rowCount() === 0) {
                try { $pdo->prepare('INSERT INTO id_counters (category, yr, last_number) VALUES (?,?,1)')->execute([$category, $year]); }
                catch (PDOException $e) { $pdo->rollBack(); continue; }   // someone else inserted first: retry
            }
            $s = $pdo->prepare('SELECT last_number FROM id_counters WHERE category = ? AND yr = ?');
            $s->execute([$category, $year]);
            $n = (int) $s->fetchColumn();
            $pdo->commit();
            return $n;
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
    throw new RuntimeException('Could not allocate a request number');
}
function format_request_id(string $category, int $year, int $n): string {
    return CATEGORIES[$category] . '-' . $year . '-' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
}
// Used only when the database is down, so the person still gets an id and the email still carries it.
function fallback_request_id(string $category): string {
    return CATEGORIES[$category] . '-' . gmdate('Y') . '-E' . strtoupper(substr(base_convert((string) time(), 10, 36), -4) . bin2hex(random_bytes(1)));
}

/* ---------- email: tiny SMTP client, falls back to mail() ---------- */
function mime_header(string $s): string { return preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }

function build_mime(string $from, string $fromName, array $to, array $cc, string $replyTo, string $subject, string $text, string $html, array $attachments): array {
    $b1 = 'mix_' . bin2hex(random_bytes(8)); $b2 = 'alt_' . bin2hex(random_bytes(8));
    $hd = [
        'From: ' . mime_header($fromName) . " <$from>",
        'To: ' . implode(', ', $to),
    ];
    if ($cc) $hd[] = 'Cc: ' . implode(', ', $cc);
    if ($replyTo) $hd[] = 'Reply-To: ' . $replyTo;
    $hd[] = 'Subject: ' . mime_header($subject);
    $hd[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
    $hd[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (preg_replace('/^.*@/', '', $from) ?: 'localhost') . '>';
    $hd[] = 'MIME-Version: 1.0';
    $hd[] = "Content-Type: multipart/mixed; boundary=\"$b1\"";
    $body  = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n";
    $body .= "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n";
    $body .= "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n--$b2--\r\n";
    foreach ($attachments as $a) {
        $name = str_replace(['"', "\r", "\n"], '', $a['name']);
        $body .= "--$b1\r\nContent-Type: {$a['mime']}; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n" . chunk_split(base64_encode((string) file_get_contents($a['path']))) . "\r\n";
    }
    $body .= "--$b1--\r\n";
    return [implode("\r\n", $hd), $body];
}

function smtp_send(array $c, string $from, array $rcpts, string $headers, string $body): void {
    $secure = $c['smtp_secure'] ?? 'ssl'; $host = $c['smtp_host']; $port = (int) ($c['smtp_port'] ?? ($secure === 'ssl' ? 465 : 587));
    $errno = 0; $errstr = '';
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 12);
    if (!$fp) throw new RuntimeException("SMTP connect failed: $errstr ($errno)");
    stream_set_timeout($fp, 20);
    $read = function () use ($fp): string {
        $out = '';
        while (($l = fgets($fp, 1024)) !== false) { $out .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
        return $out;
    };
    $cmd = function (string $line, array $ok) use ($fp, $read): string {
        if ($line !== '') fwrite($fp, $line . "\r\n");
        $r = $read();
        if (!in_array((int) substr($r, 0, 3), $ok, true)) throw new RuntimeException('SMTP error: ' . trim(substr($r, 0, 200)));
        return $r;
    };
    try {
        $cmd('', [220]);
        $ehlo = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['SERVER_NAME'] ?? 'localhost') ?: 'localhost';
        $cmd("EHLO $ehlo", [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP STARTTLS failed');
            $cmd("EHLO $ehlo", [250]);
        }
        if (!empty($c['smtp_user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode((string) $c['smtp_user']), [334]);
            $cmd(base64_encode((string) ($c['smtp_pass'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($rcpts as $r) $cmd("RCPT TO:<$r>", [250, 251]);
        $cmd('DATA', [354]);
        $data = $headers . "\r\n\r\n" . $body;
        $data = preg_replace('/(?<!\r)\n/', "\r\n", $data);
        $data = preg_replace('/^\./m', '..', $data);
        fwrite($fp, $data . "\r\n.\r\n");
        $r = $read();
        if ((int) substr($r, 0, 3) !== 250) throw new RuntimeException('SMTP rejected message: ' . trim(substr($r, 0, 200)));
        @fwrite($fp, "QUIT\r\n");
    } finally { @fclose($fp); }
}

/** Sends one email to the team (notify_to, cc notify_cc). Returns [ok(bool), error(string)]. Never throws. */
function send_mail(string $subject, string $text, string $html, array $attachments = [], string $replyTo = ''): array {
    $m = mail_config();
    $to = array_values(array_filter(array_map('trim', explode(',', (string) $m['notify_to']))));
    $cc = array_values(array_filter(array_map('trim', explode(',', (string) $m['notify_cc']))));
    if (!$to) return [false, 'No notification email is set (Admin > Business settings > Email and notifications)'];
    return send_mail_to($to, $cc, $subject, $text, $html, $attachments, $replyTo);
}
/** Sends one email to any recipients (used to send a quotation to a client). Returns [ok, error]. Never throws. */
function send_mail_to(array $to, array $cc, string $subject, string $text, string $html, array $attachments = [], string $replyTo = ''): array {
    try {
        $to = array_values(array_filter($to, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        $cc = array_values(array_filter($cc, fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        if (!$to) return [false, 'no valid recipient'];
        $m = mail_config();
        $from = (string) $m['mail_from']; $fromName = (string) $m['mail_from_name'];
        $rcpts = array_merge($to, $cc);
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $replyTo = '';
        [$headers, $body] = build_mime($from, $fromName, $to, $cc, $replyTo, $subject, $text, $html, $attachments);
        if ($m['method'] === 'file') {  // used by the tests
            file_put_contents((string) $m['mail_file'], $headers . "\r\n\r\n" . $body . "\r\n=====\r\n", FILE_APPEND); return [true, ''];
        }
        if ($m['method'] === 'smtp') {
            if ($m['smtp_host'] === '') return [false, 'SMTP is selected but no SMTP server is set'];
            smtp_send($m, $from, $rcpts, $headers, $body); return [true, ''];
        }
        // PHP mail(): pass the headers except To and Subject, which mail() sets itself
        $hdr = implode("\r\n", array_filter(explode("\r\n", $headers), fn($l) => !preg_match('/^(To|Subject):/i', $l)));
        $ok = @mail(implode(', ', $to), mime_header($subject), $body, $hdr);
        return $ok ? [true, ''] : [false, 'mail() returned false'];
    } catch (Throwable $e) { return [false, substr($e->getMessage(), 0, 300)]; }
}

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/notify.php';
