<?php
require __DIR__ . '/inc/boot.php';
$admin = require_login(true); $pdo = db(); $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cur = (string) ($_POST['current'] ?? ''); $new = (string) ($_POST['new'] ?? ''); $rep = (string) ($_POST['repeat'] ?? '');
    if (!password_verify($cur, $admin['password_hash'])) $err = 'Your current password is not correct.';
    elseif ($new !== $rep) $err = 'The two new passwords do not match.';
    elseif ($new === $cur) $err = 'Choose a password you have not used just now.';
    elseif (($pe = valid_password($new))) $err = $pe;
    else {
        $pdo->prepare('UPDATE admins SET password_hash = ?, must_change_pw = 0, password_changed_at = ?, updated_at = ? WHERE id = ?')->execute([make_hash($new), now(), now(), $admin['id']]);
        log_activity((int) $admin['id'], 'password_changed', $admin['username']);
        session_regenerate_id(true); flash('Password changed.'); redirect('index.php');
    }
}
page_head('Change password', $admin);
?>
<h1>Change password</h1>
<?php if ((int) $admin['must_change_pw']): ?><p class="muted">You are using a temporary password. Choose your own before you continue.</p><?php endif; ?>
<?php if ($err): ?><div class="flash bad" role="alert"><?= h($err) ?></div><?php endif; ?>
<div class="card"><form method="post" class="stack" style="max-width:420px"><?= csrf_field() ?>
  <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
  <label>New password<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
  <label>Repeat new password<input type="password" name="repeat" required minlength="10" autocomplete="new-password"></label>
  <p class="muted" style="margin:0">At least 10 characters, with letters and at least one number.</p>
  <button class="btn">Save password</button></form></div>
<?php page_foot();
