<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/api/lib/common.php';
require dirname(__DIR__, 2) . '/api/lib/quotes.php';

const IDLE_SECONDS = 3600;
const MAX_FAILS = 5;
const LOCK_MINUTES = 15;

function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); }

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('audtix_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_start();
}
start_session();
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin'); header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'");

function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24)); return $_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . h(csrf()) . '">'; }
function check_csrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $t = (string) ($_POST['_csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) { http_response_code(400); exit('Security check failed. Go back, reload the page and try again.'); }
}
function redirect(string $to): never { header('Location: ' . $to); exit; }
function flash(?string $msg = null, string $type = 'ok') {
    if ($msg !== null) { $_SESSION['flash'] = [$type, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function log_login(?int $adminId, string $tried, bool $ok, string $reason): void {
    try { db()->prepare('INSERT INTO admin_login_log (admin_id, username_tried, success, reason, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?)')
        ->execute([$adminId, mb_substr($tried, 0, 190), $ok ? 1 : 0, $reason, client_ip(), user_agent(), now()]); } catch (Throwable $e) {}
}
function log_activity(?int $adminId, string $action, string $target = '', string $detail = ''): void {
    try { db()->prepare('INSERT INTO admin_activity (admin_id, action, target, detail, ip, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$adminId, $action, mb_substr($target, 0, 80), mb_substr($detail, 0, 500), client_ip(), now()]); } catch (Throwable $e) {}
}

/** Current admin row, or null. Re-checked against the database on every request. */
function me(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if (!$id) return $cache = null;
    if (time() - (int) ($_SESSION['seen'] ?? 0) > IDLE_SECONDS) { $_SESSION = []; return $cache = null; }
    $s = db()->prepare('SELECT * FROM admins WHERE id = ?'); $s->execute([$id]); $a = $s->fetch();
    if (!$a || !(int) $a['is_active']) { $_SESSION = []; return $cache = null; }
    $_SESSION['seen'] = time();
    return $cache = $a;
}
function require_login(bool $allowPwChange = false): array {
    $a = me();
    if (!$a) redirect('login.php');
    if ((int) $a['must_change_pw'] && !$allowPwChange) redirect('password.php');
    check_csrf();
    return $a;
}
function require_super(): array {
    $a = require_login();
    if ($a['role'] !== 'super_admin') { http_response_code(403); exit('Only the super admin can open this page.'); }
    return $a;
}

function valid_password(string $p): ?string {
    if (strlen($p) < 10) return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) return 'Use letters and at least one number.';
    return null;
}
function make_hash(string $p): string { return password_hash($p, PASSWORD_DEFAULT); }
function temp_password(): string {
    $c = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $o = '';
    for ($i = 0; $i < 12; $i++) $o .= $c[random_int(0, strlen($c) - 1)];
    return $o . random_int(10, 99);
}

const STATUS_COLORS = ['new' => 'warning', 'in_review' => 'info', 'awaiting_client' => 'secondary', 'quoted' => 'primary', 'in_progress' => 'primary', 'completed' => 'success', 'closed' => 'success', 'spam' => 'dark'];
const STATUSES = ['new' => 'New', 'in_review' => 'In review', 'awaiting_client' => 'Waiting for client', 'quoted' => 'Quoted', 'in_progress' => 'In progress', 'completed' => 'Completed', 'closed' => 'Closed', 'spam' => 'Spam'];

require __DIR__ . '/layout.php';
