<?php
require __DIR__ . '/inc/boot.php';
$tok = (string) ($_GET['t'] ?? $_POST['t'] ?? ''); $err = ''; $row = null;
try { $s = db()->prepare('SELECT * FROM biz_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?'); $s->execute([hash('sha256', $tok), gmdate('Y-m-d H:i:s')]); $row = $s->fetch() ?: null; } catch (Throwable $e) {}
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); $pw = (string) ($_POST['password'] ?? '');
    if ($e = valid_password($pw)) $err = $e;
    else {
        $pdo = db(); $pdo->prepare('UPDATE biz_users SET password_hash = ?, must_change_pw = 0, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([make_hash($pw), $row['user_id']]);
        $pdo->prepare('UPDATE biz_reset_tokens SET used_at = ? WHERE id = ?')->execute([now(), $row['id']]);
        $u = $pdo->prepare('SELECT account_id FROM biz_users WHERE id = ?'); $u->execute([$row['user_id']]); biz_audit('password_reset', '', (int) $u->fetchColumn());
        flash('Password changed. Sign in with your new password.'); redirect('login.php');
    }
}
biz_head('Choose a new password'); ?>
<?php if (!$row): ?><div class="card narrow"><div class="flash bad">This link is invalid or has expired.</div><p class="c"><a href="forgot.php">Request a new link</a></p></div>
<?php else: ?><form method="post" class="card narrow"><?= csrf_field() ?><input type="hidden" name="t" value="<?= h($tok) ?>"><?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
<label>New password<input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus></label><button class="btn">Save password</button></form><?php endif; ?>
<?php biz_foot();
