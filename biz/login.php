<?php
require __DIR__ . '/inc/boot.php';
if (me()) redirect('index.php');
$err = ''; $email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); $email = strtolower(trim((string) ($_POST['email'] ?? ''))); $pw = (string) ($_POST['password'] ?? '');
    try {
        $s = db()->prepare('SELECT * FROM biz_users WHERE email = ?'); $s->execute([$email]); $u = $s->fetch();
        if ($u && $u['locked_until'] && $u['locked_until'] > now()) $err = 'Too many attempts. Try again in a few minutes.';
        elseif ($u && (int) $u['is_active'] && password_verify($pw, $u['password_hash'])) {
            session_regenerate_id(true); $_SESSION['biz_uid'] = (int) $u['id']; $_SESSION['biz_seen'] = time();
            db()->prepare('UPDATE biz_users SET failed_logins = 0, locked_until = NULL, last_login = ? WHERE id = ?')->execute([now(), $u['id']]); redirect('index.php');
        } else {
            if ($u) { $f = (int) $u['failed_logins'] + 1; db()->prepare('UPDATE biz_users SET failed_logins = ?, locked_until = ? WHERE id = ?')->execute([$f >= 5 ? 0 : $f, $f >= 5 ? gmdate('Y-m-d H:i:s', time() + 900) : null, $u['id']]); }
            else password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');   // keep the timing similar
            $err = 'That email and password do not match.';
        }
    } catch (Throwable $e) { $err = 'The business portal is not set up yet. Please contact the service provider.'; }
}
biz_head('Sign in'); ?>
<form method="post" class="card narrow"><?= csrf_field() ?>
  <?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
  <label>Email<input type="email" name="email" value="<?= h($email) ?>" autocomplete="username" required autofocus></label>
  <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
  <button class="btn">Sign in</button>
  <p class="muted c">New here? <a href="signup.php">Start a free <?= BIZ_TRIAL_DAYS ?>-day trial</a></p>
</form>
<?php biz_foot();
