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
page_head('Change password', $admin, 'password');
?>
<div class="row"><div class="col-md-7 col-lg-5">
<?php if ((int) $admin['must_change_pw']): ?><div class="alert alert-warning">You are using a temporary password. Choose your own before you continue.</div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger" role="alert"><?= h($err) ?></div><?php endif; ?>
<div class="card card-outline card-primary"><div class="card-body"><form method="post"><?= csrf_field() ?>
  <div class="mb-3"><label class="form-label" for="p1">Current password</label><input id="p1" class="form-control" type="password" name="current" required autocomplete="current-password"></div>
  <div class="mb-3"><label class="form-label" for="p2">New password</label><input id="p2" class="form-control" type="password" name="new" required minlength="10" autocomplete="new-password"></div>
  <div class="mb-3"><label class="form-label" for="p3">Repeat new password</label><input id="p3" class="form-control" type="password" name="repeat" required minlength="10" autocomplete="new-password"><div class="form-text">At least 10 characters, with letters and at least one number.</div></div>
  <button class="btn btn-primary">Save password</button></form></div></div>
</div></div>
<?php page_foot();
