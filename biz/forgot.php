<?php
require __DIR__ . '/inc/boot.php';
if (me()) redirect('index.php');
$done = false; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf(); $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    try {
        $pdo = db(); $ip = client_ip();
        $c = $pdo->prepare("SELECT COUNT(*) FROM submission_log WHERE ip = ? AND category = 'biz_reset' AND created_at > ?"); $c->execute([$ip, gmdate('Y-m-d H:i:s', time() - 3600)]);
        if ((int) $c->fetchColumn() >= 5) $err = 'Too many requests. Please try again later.';
        else {
            $pdo->prepare('INSERT INTO submission_log (ip, category, created_at) VALUES (?,?,?)')->execute([$ip, 'biz_reset', now()]);
            $s = $pdo->prepare('SELECT * FROM biz_users WHERE email = ? AND is_active = 1'); $s->execute([$email]); $u = $s->fetch();
            if ($u) {
                $tok = bin2hex(random_bytes(32)); $pdo->prepare('INSERT INTO biz_reset_tokens (user_id, token_hash, expires_at) VALUES (?,?,?)')->execute([$u['id'], hash('sha256', $tok), gmdate('Y-m-d H:i:s', time() + 3600)]);
                $base = (is_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                biz_mail_to($email, 'Reset your password', "Hello {$u['full_name']},\n\nUse this link within one hour to choose a new password:\n$base/reset.php?t=$tok\n\nIf you did not ask for this, ignore this email.");
                biz_audit('reset_requested', $email, (int) $u['account_id']);
            }
            $done = true;   // same answer whether or not the email exists
        }
    } catch (Throwable $e) { $err = 'This service is not available yet.'; }
}
biz_head('Forgot password'); ?>
<form method="post" class="card narrow"><?= csrf_field() ?>
<?php if ($done): ?><div class="flash ok">If that email has an account, a reset link is on its way. It works for one hour.</div><p class="c"><a href="login.php">Back to sign in</a></p>
<?php else: ?><?php if ($err): ?><div class="flash bad"><?= h($err) ?></div><?php endif; ?>
<p class="muted">Enter your email and we will send a link to choose a new password.</p><label>Email<input type="email" name="email" required autofocus></label><button class="btn">Send reset link</button><?php endif; ?>
</form>
<?php biz_foot();
