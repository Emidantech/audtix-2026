<?php
require __DIR__ . '/inc/boot.php';
if (me()) redirect('index.php');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $u = trim((string) ($_POST['username'] ?? '')); $p = (string) ($_POST['password'] ?? '');
    try {
        $pdo = db(); $ip = client_ip();
        $f = $pdo->prepare('SELECT COUNT(*) FROM admin_login_log WHERE ip = ? AND success = 0 AND created_at > ?');
        $f->execute([$ip, gmdate('Y-m-d H:i:s', time() - 900)]);
        if ((int) $f->fetchColumn() >= 20) { log_login(null, $u, false, 'ip_blocked'); $error = 'Too many attempts from this network. Try again in 15 minutes.'; }
        else {
            $s = $pdo->prepare('SELECT * FROM admins WHERE username = ? OR email = ?'); $s->execute([$u, strtolower($u)]); $a = $s->fetch();
            $dummy = '$2y$10$Xwfnno/u8jmMPA3ZCoQkr.jYh3gJia6tR6NKFlZF3G7usi2wnRZwy';
            $ok = password_verify($p, $a ? $a['password_hash'] : $dummy);
            if ($a && $a['locked_until'] && $a['locked_until'] > now()) { log_login((int) $a['id'], $u, false, 'locked'); $error = 'This account is locked for a few minutes after too many wrong passwords.'; }
            elseif ($a && $ok && (int) $a['is_active']) {
                session_regenerate_id(true);
                $_SESSION = ['admin_id' => (int) $a['id'], 'seen' => time(), 'csrf' => bin2hex(random_bytes(24))];
                $pdo->prepare('UPDATE admins SET failed_attempts = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?')->execute([now(), $ip, now(), $a['id']]);
                if (password_needs_rehash($a['password_hash'], PASSWORD_DEFAULT)) $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([make_hash($p), $a['id']]);
                log_login((int) $a['id'], $u, true, 'ok');
                redirect('index.php');
            } else {
                if ($a && $ok && !(int) $a['is_active']) { log_login((int) $a['id'], $u, false, 'disabled'); }
                elseif ($a) {
                    $n = (int) $a['failed_attempts'] + 1; $lock = $n >= MAX_FAILS ? gmdate('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null;
                    $pdo->prepare('UPDATE admins SET failed_attempts = ?, locked_until = ?, updated_at = ? WHERE id = ?')->execute([$lock ? 0 : $n, $lock, now(), $a['id']]);
                    log_login((int) $a['id'], $u, false, $lock ? 'bad_password_locked' : 'bad_password');
                } else log_login(null, $u, false, 'unknown_user');
                $error = 'Wrong username or password.';
            }
        }
    } catch (Throwable $e) { $error = 'The admin panel cannot reach its database. Check the configuration.'; }
}
page_head('Sign in');
?>
<div class="login">
  <h1><span class="dot"></span>Audtix Admin</h1>
  <p class="muted">Sign in to manage tax and audit requests.</p>
  <?php if ($error): ?><div class="flash bad" role="alert"><?= h($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <label>Username or email<input name="username" required autofocus autocomplete="username" maxlength="190"></label>
    <label>Password<input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn" type="submit">Sign in</button>
  </form>
</div>
<?php page_foot();
